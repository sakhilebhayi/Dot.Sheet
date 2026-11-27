{{-- The header bell's notification centre (EA-2, E-02). Plain forms: works on every page, with or without Livewire. --}}
@php
    $person = auth()->user();
    $unread = $person?->unreadNotifications()->count() ?? 0;
    $notices = $person?->notifications()->latest()->limit(15)->get() ?? collect();
@endphp
@if ($person)
<x-dot.bell :count="$unread" label="Notifications" id="sheet-notices" panel-label="Notifications">
    <div class="dh-panel__head">
        <span class="dh-panel__title">Notifications</span>
        @if ($unread > 0)
            <form method="POST" action="{{ route('notices.read-all') }}">
                @csrf
                <button type="submit" class="dh-mark">Mark all read</button>
            </form>
        @endif
    </div>
    @if ($notices->isEmpty())
        <p class="dh-panel__empty">Nothing yet. Mentions, approvals, sharing and changes to your companies show here.</p>
    @else
        <ul class="dh-panel__list">
            @foreach ($notices as $notice)
                <li class="dh-panel__item" @if ($notice->read_at === null) data-unread @endif>
                    <form method="POST" action="{{ route('notices.open', $notice->id) }}">
                        @csrf
                        <button type="submit" class="notice-open">
                            <span class="notice-open__text">{{ \Illuminate\Support\Str::limit((string) ($notice->data['message'] ?? $notice->data['title'] ?? 'Something changed in a workbook'), 160) }}</span>
                            <span class="notice-open__when">{{ $notice->created_at?->diffForHumans() }}@if ($notice->read_at === null)<span class="notice-sr">, unread</span>@endif</span>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
    <div class="dh-panel__foot"><a href="{{ route('profile.show') }}#emails">Choose which emails you get</a></div>
</x-dot.bell>
@endif
