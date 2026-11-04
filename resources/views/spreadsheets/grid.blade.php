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
            <button type="button" id="do-more" aria-label="More formatting" aria-expanded="false" aria-controls="formatbar">Aa</button>
        </div>
        <button class="tool" id="open-palette" type="button">Find anything<kbd>⌘K</kbd></button>
        <a class="tool quiet" href="{{ route('spreadsheets.classic', $spreadsheet) }}">Classic editor</a>
    </header>

    <div class="formatbar" id="formatbar" role="toolbar" aria-label="Format cells" hidden>
        <div class="set">
            <button type="button" id="do-italic" aria-label="Italic" aria-pressed="false"><i>I</i></button>
            <button type="button" id="do-underline" aria-label="Underline" aria-pressed="false"><u>U</u></button>
            <button type="button" id="do-strike" aria-label="Strikethrough" aria-pressed="false"><s>S</s></button>
        </div>
        <div class="set">
            <button type="button" id="do-left" aria-label="Align left" aria-pressed="false"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 4h12M2 8h7M2 12h10"/></svg></button>
            <button type="button" id="do-center" aria-label="Align centre" aria-pressed="false"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 4h12M4.5 8h7M3 12h10"/></svg></button>
            <button type="button" id="do-right" aria-label="Align right" aria-pressed="false"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 4h12M7 8h7M4 12h10"/></svg></button>
        </div>
        <div class="set">
            <button type="button" id="do-smaller" aria-label="Smaller text">A<sub>-</sub></button>
            <output id="size-now" aria-label="Text size">14</output>
            <button type="button" id="do-larger" aria-label="Larger text">A<sup>+</sup></button>
        </div>
        <div class="set" role="group" aria-labelledby="text-label"><span id="text-label">Text</span><span class="swatches" id="text-swatches"></span></div>
        <div class="set" role="group" aria-labelledby="fill-label"><span id="fill-label">Fill</span><span class="swatches" id="fill-swatches"></span></div>
        <button class="tool quiet" type="button" id="do-clear">Clear formatting</button>
        <button class="tool quiet" type="button" id="do-rules">Colour rules</button>
        <button class="tool quiet" type="button" id="do-allowed">Allowed values</button>
        <button class="tool quiet" type="button" id="do-filter">Filter</button>
        <span class="grow"></span>
        <button class="tool" type="button" id="format-close" aria-label="Close formatting">×</button>
    </div>

    <form class="rulebar" id="filterbar" aria-label="Filter rows" hidden>
        <div class="rule-line">
            <span id="filter-where">Show rows where column A is</span>
            <select id="filter-kind" aria-label="The condition">
                <option value="contains">containing</option>
                <option value="==">exactly</option>
                <option value="!=">anything but</option>
                <option value="<">less than</option>
                <option value="<=">at most</option>
                <option value=">">more than</option>
                <option value=">=">at least</option>
                <option value="between">between</option>
            </select>
            <input id="filter-value" aria-label="The value" autocomplete="off">
            <span class="allow-range" id="filter-and" hidden>
                <span>and</span>
                <input id="filter-value2" aria-label="The other value" autocomplete="off" inputmode="decimal">
            </span>
            <button class="tool" type="submit">Add filter</button>
            <span class="grow"></span>
            <button class="tool quiet" type="button" id="filter-reapply">Reapply</button>
            <button class="tool quiet" type="button" id="filter-clear">Clear filters</button>
            <button class="tool" type="button" id="filter-close" aria-label="Close filter">×</button>
        </div>
        <ul class="rule-list" id="filter-list"></ul>
    </form>

    <form class="rulebar" id="allowedbar" aria-label="Allowed values" hidden>
        <div class="rule-line">
            <span id="allow-where">Let this cell hold</span>
            <select id="allow-type" aria-label="What may go here">
                <option value="number">numbers</option>
                <option value="text_length">text</option>
                <option value="list">only these</option>
            </select>
            <span class="allow-range" id="allow-range">
                <span>from</span>
                <input id="allow-min" aria-label="The lowest" autocomplete="off" inputmode="decimal">
                <span>to</span>
                <input id="allow-max" aria-label="The highest" autocomplete="off" inputmode="decimal">
                <span id="allow-unit" hidden>characters</span>
            </span>
            <input class="allow-items" id="allow-items" aria-label="What is allowed, separated by commas" placeholder="Paid, Unpaid, Late" autocomplete="off" hidden>
            <button class="tool" type="submit">Add rule</button>
            <span class="grow"></span>
            <button class="tool" type="button" id="allow-close" aria-label="Close allowed values">×</button>
        </div>
        <ul class="rule-list" id="allowed-list"></ul>
    </form>

    <form class="rulebar" id="rulebar" aria-label="Colour rules" hidden>
        <div class="rule-line">
            <span>Colour</span>
            <select id="rule-target" aria-label="Which cells">
                <option value="column">this column</option>
                <option value="cell">this cell</option>
            </select>
            <span>when it is</span>
            <select id="rule-operator" aria-label="The condition">
                <option value="<">less than</option>
                <option value="<=">at most</option>
                <option value=">">more than</option>
                <option value=">=">at least</option>
                <option value="==">exactly</option>
                <option value="!=">anything but</option>
                <option value="contains">containing</option>
            </select>
            <input id="rule-value" aria-label="The value" autocomplete="off" spellcheck="false">
            <span class="swatches" id="rule-swatches" role="group" aria-label="Colour"></span>
            <button class="tool" type="submit">Add rule</button>
            <span class="grow"></span>
            <button class="tool" type="button" id="rule-close" aria-label="Close colour rules">×</button>
        </div>
        <ul class="rule-list" id="rule-list"></ul>
    </form>

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
                <button class="pick" id="pick" type="button" aria-label="Choose from the list" aria-expanded="false" aria-controls="picks" hidden><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6.5 8 10.5 12 6.5"/></svg></button>
                <ul class="picks" id="picks" role="listbox" aria-label="Choices" hidden></ul>
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

    <dialog id="confirm" aria-labelledby="confirm-text">
        <form method="dialog" class="confirm">
            <p id="confirm-text"></p>
            <div class="deeds">
                <button class="button quiet" value="cancel" autofocus>Keep it</button>
                <button class="button" value="yes" id="confirm-yes">Delete sheet</button>
            </div>
        </form>
    </dialog>

    <dialog id="palette" aria-label="Find anything">
        <input id="find" placeholder="A command, a function, or a cell such as B12" autocomplete="off" aria-label="Find a command, a function or a cell">
        <ul id="found"></ul>
    </dialog>

    <p class="sr" id="say" aria-live="polite"></p>

    <script type="application/json" id="boot">@json($boot, JSON_HEX_TAG | JSON_HEX_AMP)</script>
</body>
</html>
