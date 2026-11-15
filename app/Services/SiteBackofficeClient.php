<?php

namespace App\Services;

use App\Exceptions\SiteBackofficeException;
use App\Models\User;
use App\Support\ApiAuditLog;
use App\Support\SiteBackofficeResult;
use App\Support\StaffIdentity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Client for the Kadi site's signed back-office API (kadi docs/backoffice-api.md).
 *
 * Every request is signed with HMAC-SHA256 over
 * "{timestamp}.{requestId}.{METHOD}.{requestUri}.{sha256(body)}", where the body is
 * the exact bytes sent and requestUri the path plus query string as sent.
 *
 * Writes are idempotent by request id on the site: a retry after a timeout, or
 * while the first call is still running (409 without a code), reuses the same id
 * with a fresh timestamp and signature, so the action runs at most once. A
 * refusal is never retried. Each write names the staff member and is written to
 * the audit log. The secret, the signature and the headers are never logged.
 */
class SiteBackofficeClient
{
    public const PATH_PREFIX = '/api/v1/backoffice/sports';

    /**
     * Extra attempts after the first, for writes (same request id) and reads.
     */
    private const RETRIES = 2;

    /**
     * A 429 is retried once, and only when the site asks to wait no longer than this.
     */
    private const MAX_RETRY_AFTER_SECONDS = 5;

    public function isConfigured(): bool
    {
        return filled(config('services.kadi_site.url')) && filled(config('services.kadi_site.secret'));
    }

    /**
     * The signature the site expects, as lowercase hex.
     */
    public static function sign(string $secret, string $timestamp, string $requestId, string $method, string $requestUri, string $body): string
    {
        return hash_hmac('sha256', implode('.', [$timestamp, $requestId, strtoupper($method), $requestUri, hash('sha256', $body)]), $secret);
    }

    /**
     * A read, e.g. `get('reconciliation', ['date' => '2026-11-15'])`.
     *
     * @param  array<string, scalar>  $query
     *
     * @throws SiteBackofficeException
     */
    public function get(string $path, array $query = []): SiteBackofficeResult
    {
        return $this->send('GET', $path, $query, null, (string) Str::uuid());
    }

    /**
     * A write (POST, PUT or PATCH) made by a staff member, recorded in the audit log.
     *
     * Pass the request id of an earlier attempt whose outcome is unknown
     * ({@see SiteBackofficeException::outcomeUnknown()}) to retry it safely.
     *
     * @param  array<string, mixed>  $payload  The body, without `staff`.
     * @param  string  $subject  What changes, for the audit log, e.g. `SbMarket`.
     * @param  string  $event  e.g. `suspend`.
     *
     * @throws SiteBackofficeException
     */
    public function write(string $method, string $path, array $payload, User $staff, string $subject, int $subjectId, string $event, ?string $requestId = null): SiteBackofficeResult
    {
        $method = strtoupper($method);

        if (! in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            throw new \InvalidArgumentException("[{$method}] is not a back-office write method.");
        }

        // Nothing was sent, so there is no attempt to audit.
        $this->ensureConfigured();

        $requestId ??= (string) Str::uuid();
        $payload['staff'] = StaffIdentity::forSite($staff);

        try {
            $result = $this->send($method, $path, [], $payload, $requestId);
        } catch (SiteBackofficeException $e) {
            ApiAuditLog::record($subject, $subjectId, $event, $this->auditPayload($payload, $requestId), $e->statusCode, array_filter([
                'message' => $e->siteMessage !== '' ? $e->siteMessage : $e->getMessage(),
                'code' => $e->errorCode,
                'errors' => $e->errors,
            ]), system: 'KadiSite', userId: $staff->id);

            throw $e;
        }

        ApiAuditLog::record($subject, $subjectId, $event, $this->auditPayload($payload, $requestId), $result->status, array_filter([
            'data' => $result->data,
            'replayed' => $result->replayed ?: null,
        ]), system: 'KadiSite', userId: $staff->id);

        return $result;
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $payload  Null for a read.
     *
     * @throws SiteBackofficeException
     */
    private function send(string $method, string $path, array $query, ?array $payload, string $requestId): SiteBackofficeResult
    {
        $this->ensureConfigured();

        $base = rtrim((string) config('services.kadi_site.url'), '/');
        $requestUri = self::PATH_PREFIX.'/'.ltrim($path, '/')
            .($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        // The site signs the URI it receives, which includes any path the base URL has.
        $signedUri = rtrim((string) parse_url($base, PHP_URL_PATH), '/').$requestUri;
        $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $isWrite = $payload !== null;
        $rateLimitRetried = false;

        for ($attempt = 0; ; $attempt++) {
            $retriesLeft = $attempt < self::RETRIES;

            try {
                $response = $this->attempt($method, $base.$requestUri, $signedUri, $body, $requestId);
            } catch (ConnectionException) {
                $this->logFailure($method, $requestUri, 0, $requestId);

                if ($retriesLeft) {
                    $this->backOff($attempt);

                    continue;
                }

                throw new SiteBackofficeException(
                    $isWrite
                        ? 'The Kadi site did not answer, so it is not known whether the action ran. Retry it: the site runs it at most once.'
                        : 'The Kadi site did not answer. Try again shortly.',
                    requestId: $isWrite ? $requestId : null,
                );
            }

            if ($response->successful()) {
                $json = $response->json();

                return new SiteBackofficeResult(
                    data: is_array($json['data'] ?? null) ? $json['data'] : [],
                    status: $response->status(),
                    requestId: $requestId,
                    replayed: $response->header('Backoffice-Replayed') === 'true',
                );
            }

            $status = $response->status();
            $code = $this->errorCode($response);
            $this->logFailure($method, $requestUri, $status, $requestId, $code);

            $stillRunning = $isWrite && $status === 409 && $code === null;
            $readFailed = ! $isWrite && $status >= 500 && $status !== 503;

            if (($stillRunning || $readFailed) && $retriesLeft) {
                $this->backOff($attempt);

                continue;
            }

            $retryAfter = $status === 429 ? $this->retryAfter($response) : null;

            if ($status === 429 && ! $rateLimitRetried && $retryAfter !== null && $retryAfter <= self::MAX_RETRY_AFTER_SECONDS) {
                $rateLimitRetried = true;
                Sleep::for($retryAfter)->seconds();

                continue;
            }

            throw $this->failure($response, $status, $code, $isWrite ? $requestId : null, $retryAfter);
        }
    }

    /**
     * One signed attempt. A retry signs again with a fresh timestamp but the same request id.
     *
     * @throws ConnectionException
     */
    private function attempt(string $method, string $url, string $signedUri, string $body, string $requestId): Response
    {
        $timestamp = (string) now()->getTimestamp();

        $request = Http::withHeaders([
            'Accept' => 'application/json',
            'X-Backoffice-Timestamp' => $timestamp,
            'X-Backoffice-Request-Id' => $requestId,
            'X-Backoffice-Signature' => self::sign((string) config('services.kadi_site.secret'), $timestamp, $requestId, $method, $signedUri, $body),
        ])
            ->timeout((int) config('services.kadi_site.timeout', 20))
            ->connectTimeout((int) config('services.kadi_site.connect_timeout', 5));

        if ($body !== '') {
            // The exact bytes that were signed.
            $request = $request->withBody($body, 'application/json');
        }

        return $request->send($method, $url);
    }

    /**
     * @throws SiteBackofficeException
     */
    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new SiteBackofficeException('The Kadi site back office is not set up in GMS (KADI_SITE_BACKOFFICE_URL and KADI_SITE_BACKOFFICE_SECRET).');
        }
    }

    private function failure(Response $response, int $status, ?string $code, ?string $requestId, ?int $retryAfter): SiteBackofficeException
    {
        $siteMessage = (string) ($response->json('message') ?? '');
        $errors = $response->json('errors');

        $message = match (true) {
            $status === 401 => 'The Kadi site refused the signature. Check that GMS and the site share the secret and that both clocks are right.',
            $status === 503 => 'The Kadi site back office is switched off (BACKOFFICE_GMS_SECRETS is empty on the site).',
            $status === 429 => 'The Kadi site is limiting GMS requests. Try again in '.($retryAfter ?? 60).' seconds.',
            $status === 409 && $code === null => 'The Kadi site is still handling this action. Retry it in a moment: it runs at most once.',
            $siteMessage !== '' => $siteMessage,
            default => "The Kadi site answered with HTTP {$status}.",
        };

        return new SiteBackofficeException(
            $message,
            statusCode: $status,
            siteMessage: $siteMessage,
            errors: is_array($errors) ? $errors : [],
            errorCode: $code,
            requestId: $requestId,
            retryAfter: $retryAfter,
        );
    }

    private function errorCode(Response $response): ?string
    {
        $code = $response->json('code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(0, (int) $header) : null;
    }

    private function backOff(int $attempt): void
    {
        Sleep::for(500 * (2 ** $attempt))->milliseconds();
    }

    /**
     * Method, URI, status, request id and code only: never the body, headers or signature.
     */
    private function logFailure(string $method, string $requestUri, int $status, string $requestId, ?string $code = null): void
    {
        Log::warning('Kadi site back-office call failed', array_filter([
            'method' => $method,
            'uri' => $requestUri,
            'status' => $status,
            'request_id' => $requestId,
            'code' => $code,
        ], fn ($value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function auditPayload(array $payload, string $requestId): array
    {
        unset($payload['staff']);

        return ['request_id' => $requestId] + $payload;
    }
}
