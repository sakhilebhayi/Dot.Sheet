@php
    $grouped = fn (int $number) => number_format($number, 0, '', ' ');
    $tally = $spreadsheets->sum('cells_count');
    $formulas = $spreadsheets->sum('formulas_count');
    $facts = fn ($sheet) => [
        'name' => $sheet->name,
        'open' => route('spreadsheets.show', $sheet),
        'sheets' => $sheet->sheetTabs->sortBy('sort_order')->pluck('name')->implode(', '),
        'sheetCount' => $sheet->sheetTabs->count(),
        'errors' => $sheet->errors_count === 0 ? 'None' : $grouped($sheet->errors_count).($sheet->errors_count === 1 ? ' formula' : ' formulas'),
        'cells' => $grouped($sheet->cells_count),
        'cellCount' => $sheet->cells_count,
        'formulas' => $grouped($sheet->formulas_count),
        'formulaCount' => $sheet->formulas_count,
        'edited' => $sheet->edited_at->diffInSeconds(now()) < 60 ? 'Just now' : $sheet->edited_at->diffForHumans(),
        'editedAt' => $sheet->edited_at->timestamp,
        'ownerName' => $sheet->owner_id === auth()->id() ? 'You' : ($sheet->owner?->name ?? 'Someone else'),
        'shared' => collect([
            $sheet->team?->name,
            $sheet->shared_users_count > 0 ? $sheet->shared_users_count.($sheet->shared_users_count === 1 ? ' person' : ' people') : null,
        ])->filter()->implode(', ') ?: 'Nobody',
        'owner' => $sheet->owner_id === auth()->id() ? 'You' : ($sheet->owner?->name ?? 'Shared'),
        'delete' => auth()->user()->can('delete', $sheet) ? route('spreadsheets.destroy', $sheet) : null,
        'duplicate' => route('spreadsheets.duplicate', $sheet),
    ];
    $first = $spreadsheets->first();

    // The arrival: who this is and which company they are working in, from their own record. A personal team is not
    // a company, so it is not named as one.
    $person = auth()->user();
    $firstName = Str::of($person->name)->trim()->before(' ')->toString();
    $company = $person->currentTeam && ! $person->currentTeam->personal_team ? $person->currentTeam->name : null;
    $infodot = rtrim((string) config('ecosystem.platforms.infodot.url'), '/');
@endphp
<x-dot-layout title="Your spreadsheets" description="Every spreadsheet you can reach, with its shape, its size and when it last changed." page="sheets">
    <x-slot:head>
        @vite(['resources/js/pages/dashboard.js'])
    </x-slot:head>

    @if ($first)
        <x-slot:bar>
            <x-dot.filter-search id="filter" name="filter" placeholder="Filter this list" label="Filter the list by name" />
        </x-slot:bar>
    @endif

    <section class="arrive" aria-labelledby="arrive-title">
        <img class="arrive-logo" src="{{ asset('dot_sheet.png') }}" alt="Dot.Sheet" width="96" height="96">
        <div class="arrive-say">
            <h2 id="arrive-title">See what every formula reads</h2>
            <p>
                Welcome, {{ $firstName }}.
                @if ($company)
                    Dot.Sheet is where {{ $company }} keeps its figures: on dot paper, with a thread from each formula to the cells it reads.
                @else
                    Dot.Sheet is where you keep your figures: on dot paper, with a thread from each formula to the cells it reads.
                @endif
            </p>
        </div>
        <div class="arrive-do">
            <a class="button lead" href="{{ route('spreadsheets.create') }}" data-primary-action>New spreadsheet</a>
            <nav class="arrive-back" aria-label="Dot ecosystem">
                <a href="{{ $infodot }}/home">InfoDot</a>
                <a href="{{ $infodot }}/platforms">Platforms</a>
            </nav>
        </div>
    </section>

    @if ($first)

        <x-slot:status>
            <span>Spreadsheets<b id="s-count">{{ $spreadsheets->count() }}</b></span>
            <span>Cells<b id="s-cells">{{ $grouped($tally) }}</b></span>
            <span>Formulas<b id="s-formulas">{{ $grouped($formulas) }}</b></span>
        </x-slot:status>

        <div class="desk">
            <aside class="stage" id="stage" aria-label="The spreadsheet in view">
                <h2><a id="stage-title" href="{{ route('spreadsheets.show', $first) }}">{{ $first->name }}</a></h2>
                <div class="plate">
                    <svg id="print" role="img" aria-label="The shape of this spreadsheet: its cells as dots and its formulas as threads"></svg>
                    <p id="stage-empty" hidden>Nothing here yet.</p>
                </div>
                <ul class="key">
                    <li data-kind="t"><i class="t"></i>Text</li>
                    <li data-kind="n"><i class="n"></i>Numbers</li>
                    <li data-kind="l"><i class="l"></i>Negatives</li>
                    <li data-kind="f"><i class="f"></i>Formulas, with a thread to what each reads</li>
                </ul>
                <p class="part-note" id="stage-part" hidden></p>
                <dl class="rows">
                    <div><dt>Tabs</dt><dd id="stage-sheets"></dd></div>
                    <div><dt>Errors</dt><dd id="stage-errors"></dd></div>
                    <div><dt>Owner</dt><dd id="stage-owner"></dd></div>
                    <div><dt>Shared with</dt><dd id="stage-shared"></dd></div>
                </dl>
                <div class="deeds">
                    <a class="button quiet edged" id="stage-open" href="{{ route('spreadsheets.show', $first) }}">Open</a>
                    <form id="stage-duplicate" method="POST" action="{{ route('spreadsheets.duplicate', $first) }}">
                        @csrf
                        <button class="button quiet" type="submit">Duplicate</button>
                    </form>
                    <button class="button quiet away" id="stage-delete" type="button" hidden>Delete</button>
                </div>
            </aside>

            <section class="ledger" aria-label="All spreadsheets">
                <table>
                    <thead>
                        <tr>
                            <th scope="col"><span class="sr">Print</span></th>
                            <th scope="col"><button type="button" data-sort="name">Name</button></th>
                            <th scope="col" class="fig wide"><button type="button" data-sort="sheetCount">Tabs</button></th>
                            <th scope="col" class="fig"><button type="button" data-sort="cellCount">Cells</button></th>
                            <th scope="col" class="fig wide"><button type="button" data-sort="formulaCount">Formulas</button></th>
                            <th scope="col" class="fig"><button type="button" data-sort="editedAt">Edited</button></th>
                            <th scope="col" class="wide"><button type="button" data-sort="owner">Owner</button></th>
                        </tr>
                    </thead>
                    <tbody id="ledger-rows">
                        @foreach ($spreadsheets as $sheet)
                            @php($row = $facts($sheet))
                            <tr data-id="{{ $sheet->id }}" data-print="{{ route('spreadsheets.print', $sheet) }}" data-facts="{{ json_encode($row) }}">
                                <td class="thumb"><svg aria-hidden="true"></svg></td>
                                <td class="name"><a href="{{ $row['open'] }}">{{ $sheet->name }}</a></td>
                                <td class="fig wide">{{ $row['sheetCount'] }}</td>
                                <td class="fig">{{ $row['cells'] }}</td>
                                <td class="fig wide">{{ $row['formulas'] }}</td>
                                <td class="fig">{{ $row['edited'] }}</td>
                                <td class="wide">{{ $row['owner'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="none" id="ledger-none" hidden>No spreadsheet has that name.</p>
            </section>
        </div>

        <dialog id="confirm" aria-labelledby="confirm-title">
            <h2 id="confirm-title">Delete <span id="confirm-name"></span>?</h2>
            <p>This removes the spreadsheet for everyone it is shared with, and it cannot be undone.</p>
            <form method="POST" id="confirm-form" class="deeds">
                @csrf
                @method('DELETE')
                <button class="button quiet" type="button" id="confirm-keep">Cancel</button>
                <button class="button" type="submit">Delete</button>
            </form>
        </dialog>

        <script type="application/json" id="first-print">@json($firstPrint, JSON_HEX_TAG | JSON_HEX_AMP)</script>
    @else
        <div class="first">
            <div>
                <h2>Your first spreadsheet starts here</h2>
                <p>You have no spreadsheets yet, and nobody has shared one with you. Each one you make gets a print on this page: a dot for every cell and a thread for every formula.</p>
            </div>
            <figure>
                <div><svg id="example" role="img" aria-label="An example print"></svg></div>
                <figcaption>The print of a small budget, as an example.</figcaption>
            </figure>
        </div>
    @endif
</x-dot-layout>
