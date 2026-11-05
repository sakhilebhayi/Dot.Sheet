<x-dot-layout title="Activity" description="Who shared, approved, downloaded or deleted a workbook, and when." page="activity">
    <div class="parts">
        <section class="part">
            <div class="part-title">
                <h2>Activity</h2>
                <p>{{ $team ? "Who did what to {$team->name}'s workbooks and your private ones." : 'Who did what to your private workbooks.' }} Edits to cells are kept as copies of each sheet, not here.</p>
            </div>
            <div class="part-body">
                @forelse ($events as $event)
                    <div class="entry">
                        <div>
                            <div class="strong">{{ $event->action }}: {{ $event->spreadsheet_name }}</div>
                            <div class="soft">{{ $event->user_name }}, {{ $event->created_at->toDayDateTimeString() }}@if ($event->detail). {{ $event->detail }}@endif</div>
                        </div>
                    </div>
                @empty
                    <p class="hint">Nothing yet.</p>
                @endforelse
                @if ($events->hasPages())
                    <div class="deeds">
                        @if ($events->previousPageUrl())<a class="link" href="{{ $events->previousPageUrl() }}">Newer</a>@endif
                        @if ($events->nextPageUrl())<a class="link" href="{{ $events->nextPageUrl() }}">Older</a>@endif
                    </div>
                @endif
            </div>
        </section>
    </div>
</x-dot-layout>
