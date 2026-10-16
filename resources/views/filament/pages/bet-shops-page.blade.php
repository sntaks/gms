<x-filament-panels::page>
    <x-filament::tabs label="Bet shop status">
        @foreach (\App\Filament\Pages\BetShopsPage::TABS as $key => $label)
            <x-filament::tabs.item
                :active="$this->tab === $key"
                wire:click="setTab('{{ $key }}')"
            >
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
