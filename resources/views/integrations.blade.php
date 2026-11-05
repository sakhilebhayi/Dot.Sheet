<x-dot-layout title="Integrations" description="Which Dot platforms this business is connected to, and what has been sent." page="integrations">
    <div class="parts">
        <section class="part">
            <div class="part-title">
                <h2>Connected platforms</h2>
                <p>{{ $linked ? "{$team->name} is linked to its InfoDot company." : "{$team->name} is not linked to its InfoDot company yet, so nothing reaches these platforms. People are still told by this app's own email." }}</p>
            </div>
            <div class="part-body">
                @foreach ($platforms as $platform)
                    <div class="entry">
                        <div>
                            <div class="strong">{{ $platform['name'] }}</div>
                            <div class="soft">{{ $platform['does'] }}</div>
                        </div>
                        <div class="soft">
                            {{ $platform['ready'] ? 'Set up' : 'Not set up on this server' }}@if ($platform['last']). Last sent {{ \Illuminate\Support\Carbon::parse($platform['last'])->diffForHumans() }}@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="part">
            <div class="part-title">
                <h2>What has been sent</h2>
                <p>The latest {{ $listed }}. Something that could not go shows why, and can be sent again.</p>
            </div>
            <div class="part-body">
                @forelse ($deliveries as $delivery)
                    <div class="entry">
                        <div>
                            <div class="strong">{{ \App\Models\IntegrationDelivery::STATUSES[$delivery->status] ?? $delivery->status }}</div>
                            <div class="soft">{{ $delivery->eventName() }}, {{ $delivery->created_at->diffForHumans() }}</div>
                            @if ($delivery->last_error)
                                <div class="soft">{{ $delivery->last_error }}</div>
                            @endif
                        </div>
                        @if ($delivery->status === 'given_up')
                            <form method="POST" action="{{ route('integrations.again', $delivery) }}">
                                @csrf
                                <button class="link" type="submit">Send again</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="hint">Nothing yet.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-dot-layout>
