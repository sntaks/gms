<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('console')
            ->path('')
            ->login()
            ->profile(EditProfile::class, isSimple: false)
            ->brandName('Kadi GMS')
            ->colors([
                'primary' => Color::Amber,
                'secondary' => Color::Gray,
                'info' => Color::Cyan,
                'success' => Color::Green,
                'warning' => Color::Yellow,
                'danger' => Color::Red,
                'purple' => Color::Purple,
                'orange' => Color::Orange,
                'blue' => Color::Blue,
                'pink' => Color::Pink,
                'teal' => Color::Teal,
                'yellow' => Color::Yellow,
                'red' => Color::Red,
                'green' => Color::Green,
                'indigo' => Color::Indigo,
            ])
            ->defaultThemeMode(ThemeMode::Dark)
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): View => view('filament.hooks.report-styles'),
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => '<style>@media (min-width: 48rem) { .fi-resource-payouts .fi-ta-record-content-ctn > .fi-ta-actions { min-width: 7.5rem; } }</style>',
                scopes: ListPayouts::class,
            )
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('💸 Expenses & Payouts'),
                NavigationGroup::make('🎮 Players'),
                NavigationGroup::make('🏪 Bet Shops'),
                NavigationGroup::make('🎁 Referrals'),
                NavigationGroup::make('🎯 Game Results'),
                NavigationGroup::make('🤝 Shareholders'),
                NavigationGroup::make('📊 Finance'),
                NavigationGroup::make('👥 Access Management'),
                NavigationGroup::make('⚙️ Administration'),
            ])
            ->navigationItems([
                NavigationItem::make('System Logs')
                    ->url('/log-viewer')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->group('⚙️ Administration')
                    ->sort(50)
                    ->visible(fn () => auth()->user()?->isAdmin()),
            ]);
    }
}
