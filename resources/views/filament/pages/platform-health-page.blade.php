@php use App\Filament\Pages\PlatformHealthPage; @endphp
<x-filament-panels::page>
    @foreach (collect($this->checks)->groupBy('group') as $group => $checks)
        <x-filament::section :heading="$group">
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($checks as $check)
                    <div class="flex flex-wrap items-start justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $check['label'] }}</p>
                            @if (filled($check['detail']))
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm">{{ $check['value'] }}</span>
                            <x-filament::badge :color="PlatformHealthPage::statusColor($check['status'])">{{ PlatformHealthPage::statusLabel($check['status']) }}</x-filament::badge>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
