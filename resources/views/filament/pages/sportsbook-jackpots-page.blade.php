<x-filament-panels::page>
    <x-filament::tabs label="Jackpot status">
        @foreach (\App\Filament\Pages\SportsbookJackpotsPage::TABS as $key => $label)
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
