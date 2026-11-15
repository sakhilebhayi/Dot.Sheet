@props(['title', 'description' => null, 'page' => null, 'livewire' => false, 'heading' => true])
@php
    $told = session('flash.banner') ?? session('success') ?? session('status') ?? session('error') ?? $errors->first();
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ $title }}: Dot.Sheet</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <x-dot-head />
    @vite(['resources/css/dot-pages.css', 'resources/js/pages/header-search.js'])
    {{ $head ?? '' }}
    @if ($livewire)
        @livewireStyles
    @endif
</head>
<body class="shell pages">
    <x-sheet-header :page="$page" />

    <main class="sheet page" id="sheet">
        @if ($heading)
            <h1 class="pagetitle">{{ $title }}</h1>
        @else
            <h1 class="sr">{{ $title }}</h1>
        @endif
        {{ $slot }}
    </main>

    @if (filled($told) || isset($status))
        <footer class="foot">
            <p class="note" role="status">{{ $told }}</p>
            @isset($status)
                <div class="stats">{{ $status }}</div>
            @endisset
        </footer>
    @endif

    @if ($livewire)
        @livewireScripts
    @endif
</body>
</html>
