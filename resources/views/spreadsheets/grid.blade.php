<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $spreadsheet->name }}: Dot.Sheets</title>
    @vite(['resources/css/dot-grid.css', 'resources/js/grid/main.js'])
</head>
<body class="shell">
    <a class="skip" href="#sheet">Skip to the sheet</a>

    <div class="top">
    <header class="bar">
        <a class="mark" href="{{ route('dashboard') }}" aria-label="All spreadsheets"></a>
        <h1>{{ $spreadsheet->name }}</h1>
        <label class="line">
            <span id="ref" aria-live="off"></span>
            <span class="sr">Cell contents</span>
            <input id="line" autocomplete="off" spellcheck="false">
        </label>
        <div class="fmt" role="toolbar" aria-label="Format selection">
            <button type="button" id="do-bold" aria-label="Bold" aria-pressed="false"><b>B</b></button>
            <button type="button" id="do-percent" aria-label="Percent">%</button>
            <button type="button" id="do-rand" aria-label="Rand">R</button>
        </div>
        <button class="tool" id="open-palette" type="button">Find anything<kbd>⌘K</kbd></button>
        <a class="tool quiet" href="{{ route('spreadsheets.classic', $spreadsheet) }}">Classic editor</a>
    </header>

    <form class="findbar" id="findbar" role="search" hidden>
        <label><span class="sr">Find</span><input id="find-text" placeholder="Find" autocomplete="off" spellcheck="false"></label>
        @if ($boot['canEdit'])
            <label><span class="sr">Replace with</span><input id="replace-text" placeholder="Replace with" autocomplete="off" spellcheck="false"></label>
        @endif
        <label class="tick"><input type="checkbox" id="find-case">Match case</label>
        <label class="tick"><input type="checkbox" id="find-whole">Whole cell</label>
        <label class="tick"><input type="checkbox" id="find-all">All sheets</label>
        <span class="find-count" id="find-count" role="status"></span>
        <span class="grow"></span>
        <button class="tool" type="button" id="find-prev">Previous</button>
        <button class="tool" type="button" id="find-next">Next</button>
        @if ($boot['canEdit'])
            <button class="tool" type="button" id="replace-one">Replace</button>
            <button class="tool" type="button" id="replace-all">Replace all</button>
        @endif
        <button class="tool" type="button" id="find-close" aria-label="Close find">×</button>
    </form>
    </div>

    <main class="sheet" id="sheet" tabindex="0" role="application" aria-roledescription="spreadsheet" aria-label="{{ $spreadsheet->name }}">
        <div class="canvas" id="canvas">
            <div class="head" id="head">
                <div class="head-cols" id="head-cols"></div>
                <div class="head-frozen" id="head-frozen"></div>
            </div>
            <div class="body" id="body">
                <div class="pinned" id="pinned">
                    <div class="top-rows" id="top-rows"></div>
                    <div class="corner" id="corner"></div>
                </div>
                <div class="layer" id="cells"></div>
                <svg class="threads" id="threads" aria-hidden="true"></svg>
                <div class="handle" id="handle" title="Drag to fill, or double-click to fill down" hidden></div>
                <div class="frozen" id="frozen"></div>
            </div>
        </div>
    </main>

    <footer class="foot">
        <nav class="tabs" id="tabs" aria-label="Sheets"></nav>
        <p class="note" id="note" role="status"></p>
        <div class="stats">
            <span>Sum<b id="s-sum">-</b></span><span>Average<b id="s-avg">-</b></span><span>Count<b id="s-count">0</b></span>
        </div>
    </footer>

    <dialog id="palette" aria-label="Find anything">
        <input id="find" placeholder="A command, a function, or a cell such as B12" autocomplete="off" aria-label="Find a command, a function or a cell">
        <ul id="found"></ul>
    </dialog>

    <p class="sr" id="say" aria-live="polite"></p>

    <script type="application/json" id="boot">@json($boot, JSON_HEX_TAG | JSON_HEX_AMP)</script>
</body>
</html>
