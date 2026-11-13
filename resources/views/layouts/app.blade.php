<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? 'Dot.Sheet' }}</title>
    @if (config('sheet.scripts_enabled'))
        <meta name="sheet-scripts-enabled" content="1">
    @endif
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/signed-in.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="classic">
    <x-banner />

    {{ $slot }}

    @stack('modals')
    @livewireScripts
</body>
</html>
