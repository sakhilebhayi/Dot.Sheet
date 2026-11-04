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
        <button class="tool quiet" id="do-comments" type="button" aria-expanded="false" aria-controls="notes">Comments<b id="notes-count" hidden></b></button>
        <a class="tool quiet" href="{{ route('spreadsheets.classic', $spreadsheet) }}">Classic editor</a>
        @if ($boot['canShare'])
            <button class="button share" id="do-share" type="button" aria-expanded="false" aria-controls="sharing">Share</button>
        @endif
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
            <label class="sr" for="number-kind">How numbers show</label>
            <select id="number-kind">
                <option value="general">General</option>
                <option value="number">Number</option>
                <option value="percent">Percent</option>
                <option value="currency:ZAR">Rand</option>
                <option value="currency:USD">Dollar</option>
                <option value="currency:EUR">Euro</option>
                <option value="currency:GBP">Pound</option>
                <option value="date:iso">2026-11-04</option>
                <option value="date:long">4 Nov 2026</option>
                <option value="date:dmy">04/11/2026</option>
                <option value="time">Time</option>
                <option value="datetime">Date and time</option>
                <option value="scientific">Scientific</option>
                <option value="text">Plain text</option>
            </select>
            <button type="button" id="do-fewer" aria-label="Fewer decimal places">.0</button>
            <button type="button" id="do-more-places" aria-label="More decimal places">.00</button>
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

    <div class="work">
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
                <button class="noted-here" id="noted-here" type="button" hidden></button>
            </div>
        </div>
    </main>

    <aside class="dock" id="notes" aria-label="Comments" hidden>
        <div class="dock-head">
            <h2>Comments</h2>
            <button class="tool" type="button" id="notes-close" aria-label="Close comments">×</button>
        </div>
        <ul class="notes-list" id="notes-list" tabindex="-1"></ul>
        <form class="notes-form" id="notes-form">
            <label for="notes-text" id="notes-on">Comment</label>
            <textarea id="notes-text" rows="2" maxlength="1000" placeholder="Use @name to tell someone"></textarea>
            <button class="button" type="submit">Comment</button>
        </form>
    </aside>

    <aside class="dock" id="pivots" aria-label="Pivot tables" hidden>
        <div class="dock-head">
            <h2>Pivot tables</h2>
            <button class="tool" type="button" id="pivots-close" aria-label="Close pivot tables">×</button>
        </div>
        <div class="share-body">
            <figure class="pivot-view" id="pivots-view" hidden>
                <figcaption id="pivots-heading"></figcaption>
                <div class="pivot-scroll"><table class="pivot-table" id="pivots-table"></table></div>
                <p class="dock-note" id="pivots-said" hidden></p>
            </figure>
            <form class="pivot-form" id="pivots-form">
                <label for="pivots-range">Summarise a table with headings</label>
                <input id="pivots-range" autocomplete="off" spellcheck="false" maxlength="40">
                <p class="pivot-sentence">
                    <select id="pivots-aggregation" aria-label="How to summarise">
                        <option value="sum">Sum</option>
                        <option value="count">Count</option>
                        <option value="average">Average</option>
                        <option value="min">Lowest</option>
                        <option value="max">Highest</option>
                    </select>
                    <span>of</span>
                    <select id="pivots-value" aria-label="What to summarise"></select>
                    <span>by</span>
                    <select id="pivots-row" aria-label="What to group by"></select>
                    <span>and</span>
                    <select id="pivots-across" aria-label="What to group across, if anything"></select>
                </p>
                <p class="dock-note" id="pivots-hint" hidden>Select a table with its headings in the first row.</p>
                <button class="button" type="submit" disabled>Add</button>
            </form>
            <ul class="people" id="pivots-list"></ul>
        </div>
    </aside>

    <aside class="dock" id="charts" aria-label="Charts" hidden>
        <div class="dock-head">
            <h2>Charts</h2>
            <button class="tool" type="button" id="charts-close" aria-label="Close charts">×</button>
        </div>
        <div class="share-body">
            <figure class="chart-view" id="charts-view" hidden>
                <figcaption id="charts-heading"></figcaption>
                <div class="chart-canvas"><canvas id="charts-canvas" role="img"></canvas></div>
                <p class="dock-note" id="charts-said" hidden></p>
                <div class="deeds"><button type="button" id="charts-figures" aria-expanded="false" aria-controls="charts-table">Show the figures</button></div>
                <table class="chart-table" id="charts-table" hidden></table>
            </figure>
            <form class="chart-form" id="charts-form">
                <label for="charts-title">Add a chart of the selection</label>
                <input id="charts-title" autocomplete="off" maxlength="100" placeholder="Title">
                <select id="charts-type" aria-label="Kind of chart">
                    <option value="bar">Bars</option>
                    <option value="line">Line</option>
                    <option value="area">Area</option>
                    <option value="pie">Pie</option>
                    <option value="doughnut">Doughnut</option>
                    <option value="scatter">Scatter</option>
                </select>
                <input id="charts-range" autocomplete="off" spellcheck="false" maxlength="40" aria-label="Which cells hold the figures">
                <input id="charts-labels" autocomplete="off" spellcheck="false" maxlength="40" aria-label="Which cells hold the labels, if any" placeholder="Labels, if apart">
                <button class="button" type="submit">Add</button>
            </form>
            <ul class="people" id="charts-list"></ul>
        </div>
    </aside>

    @if ($boot['canEdit'])
        <aside class="dock" id="macros" aria-label="Macros" hidden>
            <div class="dock-head">
                <h2>Macros</h2>
                <button class="tool" type="button" id="macros-close" aria-label="Close macros">×</button>
            </div>
            <form class="dock-form" id="macros-form">
                <label for="macros-name">Record a macro</label>
                <input id="macros-name" autocomplete="off" maxlength="80" placeholder="Give it a name, if you like">
                <button class="button" type="submit" id="macros-record">Record</button>
                <button class="button" type="submit" id="macros-stop" hidden>Stop and save</button>
                <p class="dock-note" id="macros-state" role="status"></p>
                <div class="deeds"><button type="button" id="macros-discard" hidden>Discard the recording</button></div>
            </form>
            <p class="dock-note">A macro plays on the sheet in view, at the same cells.</p>
            <ul class="people dock-list" id="macros-list"></ul>
        </aside>
    @endif

    <aside class="dock" id="names" aria-label="Named ranges" hidden>
        <div class="dock-head">
            <h2>Named ranges</h2>
            <button class="tool" type="button" id="names-close" aria-label="Close named ranges">×</button>
        </div>
        <p class="dock-note">A name stands for cells in a formula, on this sheet only.</p>
        <form class="dock-form names-form" id="names-form">
            <label for="names-name">Name the selection</label>
            <input id="names-name" autocomplete="off" spellcheck="false" maxlength="64" placeholder="Revenue">
            <input id="names-range" autocomplete="off" spellcheck="false" maxlength="40" aria-label="Which cells">
            <button class="button" type="submit">Add</button>
        </form>
        <ul class="people dock-list" id="names-list"></ul>
    </aside>

    <div class="formula-help" id="formula-help" role="listbox" aria-label="Suggestions" hidden></div>

    <aside class="dock" id="assistant" aria-label="Function assistant" hidden>
        <div class="dock-head">
            <h2>Functions</h2>
            <button class="tool" type="button" id="assistant-close" aria-label="Close the function assistant">×</button>
        </div>
        <input class="assistant-search" id="assistant-search" autocomplete="off" spellcheck="false" placeholder="What should it do?" aria-label="Find a function">
        <ul class="assistant-list" id="assistant-list"></ul>
        <div class="assistant-detail" id="assistant-detail" hidden></div>
    </aside>

    <aside class="dock" id="protection" aria-label="Protected cells" hidden>
        <div class="dock-head">
            <h2>Protected cells</h2>
            <button class="tool" type="button" id="protection-close" aria-label="Close protected cells">×</button>
        </div>
        <p class="dock-note">Only the owner and admins can change what is protected. Everyone who can open the workbook can still read it.</p>
        <form class="dock-form protection-form" id="protection-form">
            <label for="protection-range">Protect the selection</label>
            <input id="protection-range" autocomplete="off" spellcheck="false" maxlength="40" aria-label="Which cells">
            <input id="protection-label" autocomplete="off" maxlength="80" placeholder="Label" aria-label="Label, if it needs one">
            <button class="button" type="submit">Protect</button>
        </form>
        <div class="dock-note"><button class="button quiet" type="button" id="protection-whole">Protect the whole sheet</button></div>
        <ul class="people dock-list" id="protection-list"></ul>
    </aside>

    <aside class="dock" id="copies" aria-label="Version history" hidden>
        <div class="dock-head">
            <h2>History</h2>
            <button class="tool" type="button" id="copies-close" aria-label="Close history">×</button>
        </div>
        <p class="dock-note" id="copies-whose"></p>
        <form class="dock-form" id="copies-form">
            <label for="copies-label">Save a copy as it is now</label>
            <input id="copies-label" autocomplete="off" maxlength="120" placeholder="Give it a name, if you like">
            <button class="button" type="submit">Save</button>
        </form>
        <ul class="people dock-list" id="copies-list"></ul>
    </aside>

    @if ($boot['canShare'])
        <aside class="dock" id="sharing" aria-label="Share" hidden>
            <div class="dock-head">
                <h2>Share</h2>
                <button class="tool" type="button" id="sharing-close" aria-label="Close sharing">×</button>
            </div>
            <div class="share-body">
                <form class="share-form" id="share-form" novalidate>
                    <label for="share-email">Add someone by email</label>
                    <input id="share-email" type="email" autocomplete="off" spellcheck="false" maxlength="255" placeholder="name@company.co.za">
                    <select id="share-permission" aria-label="What they can do">
                        <option value="view">Can read</option>
                        <option value="comment">Can comment</option>
                        <option value="edit">Can edit</option>
                        <option value="admin">Can edit and share</option>
                    </select>
                    <button class="button" type="submit">Share</button>
                    <p>No account yet? They get a link for you to send, good for <input id="share-days" type="number" min="1" max="90" value="7" aria-label="Days the link lasts"> days. Nothing is emailed.</p>
                </form>

                <h3>Who has access</h3>
                <ul class="people" id="share-people"></ul>

                <div id="share-waiting-block" hidden>
                    <h3>Waiting to accept</h3>
                    <ul class="people" id="share-waiting"></ul>
                </div>

                <h3>Link for anyone</h3>
                <label class="tick"><input type="checkbox" id="share-link-on">Anyone with the link can read the first sheet, without signing in</label>
                <div class="share-link" id="share-link-rest" hidden>
                    <p class="share-gone" id="share-link-state" hidden>This link has expired. Choose a later time or clear it.</p>
                    <div class="share-url">
                        <input id="share-link-url" readonly aria-label="The link">
                        <button class="tool" type="button" id="share-link-copy">Copy</button>
                    </div>
                    <label for="share-link-expiry">Stops working on, if you want it to</label>
                    <input id="share-link-expiry" type="datetime-local">
                    <div class="deeds"><button type="button" id="share-link-new">Make a new link</button></div>
                </div>
            </div>
        </aside>
    @endif
    </div>

    <footer class="foot">
        <nav class="tabs" id="tabs" aria-label="Sheets"></nav>
        <p class="note" id="note" role="status"></p>
        <div class="stats">
            <span>Sum<b id="s-sum">-</b></span><span>Average<b id="s-avg">-</b></span><span>Count<b id="s-count">0</b></span>
        </div>
    </footer>

    <input id="import-file" type="file" accept=".csv,.xlsx,.xls" aria-label="Import a file" hidden>

    <dialog id="confirm" aria-labelledby="confirm-text">
        <form method="dialog" class="confirm">
            <p id="confirm-text"></p>
            <div class="deeds">
                <button class="button quiet" id="confirm-no" value="cancel" autofocus>Keep it</button>
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
