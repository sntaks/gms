@php
    use App\Livewire\CustomerCompliancePanel;
    use App\Support\Format;
@endphp
<div class="space-y-4">
    @if ($apiError)
        <p class="text-sm text-warning-600 dark:text-warning-400">KadiApi could not be reached. Refresh the page to try again.</p>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <x-filament::badge :color="in_array((int) ($state['status'] ?? 0), [2, 5], true) ? 'danger' : 'success'">
                    {{ CustomerCompliancePanel::statusLabel($state['status'] ?? null) }}
                </x-filament::badge>
                <span>{{ ($state['can_play'] ?? false) ? 'Can play' : 'Cannot play' }}</span>
                @if ($state['exclusion'] ?? null)
                    <span class="text-warning-600 dark:text-warning-400">{{ CustomerCompliancePanel::breakLabel($state['exclusion']['type'] ?? null) }} until {{ Format::dateTime($state['exclusion']['ends_at'] ?? null) }}</span>
                @endif
            </div>
            <div class="flex gap-2">
                {{ $this->changeStatusAction }}
                {{ $this->startBreakAction }}
            </div>
        </div>

        <div>
            <p class="text-sm font-semibold">Deposit limits</p>
            @forelse ($state['deposit_limits'] ?? [] as $limit)
                <p class="text-sm">{{ CustomerCompliancePanel::limitLine($limit) }}</p>
            @empty
                <p class="text-sm text-gray-500">The player has set no deposit limits.</p>
            @endforelse
        </div>

        <div>
            <p class="text-sm font-semibold">Breaks</p>
            @forelse ($exclusions as $exclusion)
                <p class="text-sm">{{ CustomerCompliancePanel::breakLabel($exclusion['type'] ?? null) }} ({{ $exclusion['duration'] ?? '' }}): {{ Format::dateTime($exclusion['starts_at'] ?? null) }} – {{ Format::dateTime($exclusion['ends_at'] ?? null) }}</p>
            @empty
                <p class="text-sm text-gray-500">No cool-offs or self-exclusions.</p>
            @endforelse
        </div>

        <div>
            <p class="text-sm font-semibold">Status history</p>
            @forelse ($statusChanges as $change)
                <p class="text-sm">{{ CustomerCompliancePanel::changeLine($change) }}</p>
            @empty
                <p class="text-sm text-gray-500">No status changes.</p>
            @endforelse
        </div>
    @endif

    <x-filament-actions::modals />
</div>
