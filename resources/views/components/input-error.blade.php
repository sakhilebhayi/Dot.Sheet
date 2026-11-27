@props(['for'])

@error($for)
    {{-- The id is what the field's aria-describedby points at (EA-5, A-03). --}}
    <p id="{{ 'error-'.\Illuminate\Support\Str::slug($for) }}" {{ $attributes->merge(['class' => 'error text-sm text-red-600 dark:text-red-400']) }}>{{ $message }}</p>
@enderror
