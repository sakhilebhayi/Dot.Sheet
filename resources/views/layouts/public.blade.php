<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    {{-- A share link is a capability: the token is in the URL. Keep it out of search indexes and out of
         the Referer header, and load nothing from third parties and nothing that talks to the server. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preload" href="/dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/dot-ui/css/dot-roboto.css">
    <link rel="stylesheet" href="/dot-ui/css/dot-type.css">
    @vite(['resources/css/signed-in.css'])
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: var(--dot-font-sans); font-size: 14px; line-height: 1.5; background: #f9fafb; color: #111827; }
        h1, p { margin: 0; }
        table { border-collapse: collapse; }
        @media (prefers-color-scheme: dark) { body { background: #111827; color: #f3f4f6; } }
    </style>
</head>
<body>
    <main>{{ $slot }}</main>
</body>
</html>
