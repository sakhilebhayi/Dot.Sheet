@props(['roles', 'model', 'current' => null])

<div class="choices" role="radiogroup">
    @foreach ($roles as $role)
        <button type="button" role="radio" aria-checked="{{ $current === $role->key ? 'true' : 'false' }}" wire:click="$set('{{ $model }}', '{{ $role->key }}')">
            <b>{{ $role->name }}</b>
            <span>{{ $role->description }}</span>
        </button>
    @endforeach
</div>
