@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <h2>{{ $title }}</h2>

    <div class="modal-body">
        {{ $content }}
    </div>

    <div class="deeds">
        {{ $footer }}
    </div>
</x-modal>
