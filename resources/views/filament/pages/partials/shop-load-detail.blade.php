@php
    use App\Support\Format;
@endphp

@if ($load === null)
    <x-filament::section compact>
        <p class="text-sm text-gray-500 dark:text-gray-400">This load could not be loaded from KadiApi. It may not exist, or KadiApi is unreachable.</p>
    </x-filament::section>
@else
    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Status</dt>
            <dd class="mt-1"><x-filament::badge :color="$statusColor">{{ $statusLabel }}</x-filament::badge></dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Load</dt>
            <dd class="mt-1 font-mono text-sm break-all">{{ $load['load_id'] ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Shop</dt>
            <dd class="mt-1 text-sm">
                @if ($shopUrl)
                    <x-filament::link :href="$shopUrl">{{ $load['shop_no'] ?? '—' }}</x-filament::link>
                @else
                    {{ $load['shop_no'] ?? '—' }}
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Player</dt>
            <dd class="mt-1 text-sm">
                @if ($customerUrl)
                    <x-filament::link :href="$customerUrl">{{ $load['customer_name'] ?? 'Customer #'.($load['customer_id'] ?? '?') }}</x-filament::link>
                @else
                    {{ $load['customer_name'] ?? 'Customer #'.($load['customer_id'] ?? '?') }}
                @endif
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ Format::playerNo($load['player']['player_no'] ?? null) }}</span>
            </dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Cash paid</dt>
            <dd class="mt-1 text-sm font-semibold">{{ Format::money($load['gross_amount'] ?? 0) }}</dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Excise duty · to wallet</dt>
            <dd class="mt-1 text-sm">{{ Format::money($load['excise_amount'] ?? 0) }} · {{ Format::money($load['net_amount'] ?? 0) }}</dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Loaded</dt>
            <dd class="mt-1 text-sm">{{ Format::dateTime($load['loaded_at'] ?? null) }}</dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Reversed</dt>
            <dd class="mt-1 text-sm">
                {{ Format::dateTime($load['reversed_at'] ?? null) }}
                @if (filled($load['reversal_reason'] ?? null))
                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $load['reversal_reason'] }}</span>
                @endif
            </dd>
        </div>
    </dl>
@endif
