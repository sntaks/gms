<?php

use App\Http\Controllers\B2CBalanceResultController;
use App\Http\Controllers\B2CBalanceTimeoutController;
use App\Http\Controllers\C2BBalanceResultController;
use App\Http\Controllers\C2BBalanceTimeoutController;
use App\Http\Controllers\FinanceExportController;
use App\Http\Controllers\MpesaB2CResultController;
use App\Http\Controllers\StatsExportController;
use App\Models\User;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::post('/b2c/result', MpesaB2CResultController::class);
Route::post('/b2c/timeout', MpesaB2CResultController::class);
Route::post('/b2c/balance/result', B2CBalanceResultController::class);
Route::post('/b2c/balance/timeout', B2CBalanceTimeoutController::class);
Route::post('/c2b/balance/result', C2BBalanceResultController::class);
Route::post('/c2b/balance/timeout', C2BBalanceTimeoutController::class);

Route::get('/finance/export/{report}', FinanceExportController::class)
    ->middleware(Authenticate::class)
    ->name('finance.export');

Route::get('/stats/export/{report}', StatsExportController::class)
    ->middleware(Authenticate::class)
    ->name('stats.export');

Route::get('/email/preview/{type}', function (string $type) {
    $user = auth()->user() ?? User::first();

    return match ($type) {
        'verify' => view('mail.verify-email', [
            'user' => (object) ['name' => $user->name],
            'verificationUrl' => '#',
            'appName' => config('app.name'),
        ]),
        'welcome' => view('mail.welcome', [
            'user' => $user,
            'appName' => config('app.name'),
            'appUrl' => config('app.url'),
        ]),
        'security' => view('mail.security-alert', [
            'user' => $user,
            'change' => 'Email address changed',
            'when' => now()->format('j M Y, H:i T'),
            'appName' => config('app.name'),
            'appUrl' => config('app.url'),
        ]),
        default => abort(404),
    };
});
