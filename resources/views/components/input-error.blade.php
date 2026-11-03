@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'error text-sm text-red-600 dark:text-red-400']) }}>{{ $message }}</p>
@enderror
