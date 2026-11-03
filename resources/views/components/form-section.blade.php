@props(['submit'])

<section {{ $attributes->merge(['class' => 'part']) }}>
    <div class="part-title">
        <h2>{{ $title }}</h2>
        <p>{{ $description }}</p>
    </div>

    <form class="part-body" wire:submit="{{ $submit }}">
        <div class="fields">
            {{ $form }}
        </div>

        @if (isset($actions))
            <div class="deeds">
                {{ $actions }}
            </div>
        @endif
    </form>
</section>
