<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Verify a request sent to the Kadi site's back office the way the site's
 * VerifyBackofficeSignature middleware does (kadi docs/backoffice-api.md).
 */
function siteAcceptsSignature(Request $request, string $secret = 'test-secret'): bool
{
    $uri = $request->toPsrRequest()->getUri();
    $requestUri = $uri->getPath().($uri->getQuery() !== '' ? '?'.$uri->getQuery() : '');
    $expected = hash_hmac('sha256', implode('.', [
        $request->header('X-Backoffice-Timestamp')[0] ?? '',
        $request->header('X-Backoffice-Request-Id')[0] ?? '',
        $request->method(),
        $requestUri,
        hash('sha256', $request->body()),
    ]), $secret);

    return hash_equals($expected, $request->header('X-Backoffice-Signature')[0] ?? '');
}

/**
 * Point GMS at a fake Kadi site back office signed with `test-secret`.
 */
function useFakeSiteBackoffice(): void
{
    config([
        'services.kadi_site.url' => 'https://kadi.test',
        'services.kadi_site.secret' => 'test-secret',
    ]);
}
