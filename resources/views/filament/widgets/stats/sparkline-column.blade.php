<div style="padding: 0.5rem 0.75rem;">
    <x-stats.sparkline :values="collect($getState() ?? [])->pluck('ggr')->all()" />
</div>
