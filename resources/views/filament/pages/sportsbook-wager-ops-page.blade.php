<x-filament-panels::page>
    <x-filament::tabs label="Wager op status">
        @foreach (\App\Filament\Pages\SportsbookWagerOpsPage::TABS as $key => $label)
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
