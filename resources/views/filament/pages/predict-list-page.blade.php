<x-filament-panels::page>
    @if ($this::tabs() !== [])
        <x-filament::tabs>
            @foreach ($this::tabs() as $key => $label)
                <x-filament::tabs.item
                    :active="$this->tab === $key"
                    wire:click="setTab('{{ $key }}')"
                >
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
