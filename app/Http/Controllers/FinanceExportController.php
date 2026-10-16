<?php

namespace App\Http\Controllers;

use App\Exceptions\GameApiException;
use App\Models\User;
use App\Services\GameApiService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Streams a wallet API finance CSV to an admin. The request is proxied
 * server-side so the API key never reaches the browser.
 */
class FinanceExportController extends Controller
{
    /**
     * Exports opened to a permission other than the admin finance default.
     *
     * @var array<string, string>
     */
    public const REPORT_PERMISSIONS = [
        'deposits' => 'deposits.view',
    ];

    public function __invoke(Request $request, string $report, GameApiService $gameApi): Response
    {
        $user = $request->user();

        abort_unless(in_array($report, GameApiService::FINANCE_EXPORTS, true), 404);
        abort_unless($this->canExport($user, $report), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['nullable', 'in:day,week,month'],
            'exclude_test' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'max:30'],
            'kind' => ['nullable', 'string', 'max:30'],
            'category' => ['nullable', 'string', 'max:30'],
            'entry_type' => ['nullable', 'string', 'max:50'],
            'wallet_type' => ['nullable', 'string', 'max:30'],
            'outcome' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:30'],
            'sort' => ['nullable', 'string', 'max:30'],
            'customer_id' => ['nullable', 'integer'],
            'bet_shop_id' => ['nullable', 'integer'],
            'wallet_id' => ['nullable', 'integer'],
            'game_type' => ['nullable', 'integer'],
            'players' => ['nullable', 'integer'],
            'jp_rounds' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        try {
            $csv = $gameApi->downloadFinanceCsv($report, $filters);
        } catch (GameApiException $e) {
            Log::warning('Finance CSV export failed', ['report' => $report, 'error' => $e->getMessage()]);

            abort($e->statusCode === 429 ? 429 : 502, $e->apiMessage !== '' ? $e->apiMessage : 'The wallet API could not produce this export.');
        }

        Log::info('Finance CSV exported', ['report' => $report, 'user_id' => $user->id]);

        return response($csv['body'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$csv['filename'].'"',
        ]);
    }

    /**
     * Exports follow the page they come from: most are admin finance reports,
     * but a report with its own permission (deposits, for support) uses that.
     */
    protected function canExport(?User $user, string $report): bool
    {
        if ($user === null) {
            return false;
        }

        if (isset(self::REPORT_PERMISSIONS[$report])) {
            return $user->hasPermissionTo(self::REPORT_PERMISSIONS[$report]);
        }

        return $user->isAdmin() && $user->hasPermissionTo('reports.view');
    }
}
