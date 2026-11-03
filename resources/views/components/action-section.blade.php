<section {{ $attributes->merge(['class' => 'part']) }}>
    <div class="part-title">
        <h2>{{ $title }}</h2>
        <p>{{ $description }}</p>
    </div>

    <div class="part-body">
        {{ $content }}
    </div>
</section>
