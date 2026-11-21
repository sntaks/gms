@if ($notes === [])
    <p style="font-size: 0.875rem; color: var(--gray-500);">The definitions could not be loaded.</p>
@else
    <ul style="display: flex; flex-direction: column; gap: 0.5rem; padding-left: 1rem; list-style: disc; font-size: 0.875rem;">
        @foreach ($notes as $note)
            <li>{{ $note }}</li>
        @endforeach
    </ul>
@endif
