@props(['empty' => null])

<div {{ $attributes->class('overflow-x-auto rounded-panel border border-border') }}>
    <table class="w-full text-start text-small">
        @isset($head)
            <thead class="bg-surface-sunken text-ink-muted">
                <tr>{{ $head }}</tr>
            </thead>
        @endisset
        <tbody class="divide-y divide-border">
            @if ($slot->isEmpty() && $empty)
                <tr><td class="px-4 py-6 text-center text-ink-muted" data-test="table-empty">{{ $empty }}</td></tr>
            @else
                {{ $slot }}
            @endif
        </tbody>
    </table>
</div>

@isset($pagination)
    <div class="mt-4">{{ $pagination }}</div>
@endisset