<?php

namespace App\Http\Controllers;

use App\Exceptions\GameApiException;
use App\Services\GameApiService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Streams a KadiApi statistics CSV (`/stats/export/{report}`) with the page's query. The request is
 * proxied server-side so the API key never reaches the browser. Statistics are company figures, so
 * the finance-report gate applies.
 */
class StatsExportController extends Controller
{
    public function __invoke(Request $request, string $report, GameApiService $gameApi): Response
    {
        $user = $request->user();

        abort_unless(in_array($report, GameApiService::STATS_EXPORTS, true), 404);
        abort_unless($user?->canViewFinanceReports() ?? false, 403);

        $filters = $request->validate([
            'period' => ['nullable', 'in:today,yesterday,7d,30d,this_month,last_month,this_year'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['nullable', 'in:day,week,month'],
            'exclude_test' => ['nullable', 'boolean'],
            'game' => ['nullable', 'string', 'max:50'],
            'product' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', 'in:turnover,ggr,deposits,net_loss,wins'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        try {
            $csv = $gameApi->downloadStatsCsv($report, $filters);
        } catch (GameApiException $e) {
            Log::warning('Statistics CSV export failed', ['report' => $report, 'error' => $e->getMessage()]);

            abort($e->statusCode === 429 ? 429 : 502, $e->apiMessage !== '' ? $e->apiMessage : 'KadiApi could not produce this export.');
        }

        Log::info('Statistics CSV exported', ['report' => $report, 'user_id' => $user->id]);

        return response($csv['body'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$csv['filename'].'"',
        ]);
    }
}
