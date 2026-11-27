@props(['disabled' => false, 'error' => null])
@php
    // EA-5 (A-03): a field with an error says so to assistive technology and points at the message, which
    // <x-input-error for="..."> renders with the matching id. The key is the field's name in the error bag.
    $field = $error ?? $attributes->get('name') ?? $attributes->get('wire:model') ?? $attributes->get('wire:model.live') ?? $attributes->get('id');
    $field = is_string($field) ? preg_replace('/^state\./', '', $field) : null;
    $invalid = $field !== null && $errors->has($field);
@endphp
<input {{ $disabled ? 'disabled' : '' }} @if ($invalid) aria-invalid="true" aria-describedby="{{ 'error-'.\Illuminate\Support\Str::slug($field) }}" @endif {!! $attributes->merge(['class' => 'dot-field border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm']) !!}>
