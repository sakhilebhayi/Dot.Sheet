<x-dot-layout title="New spreadsheet" description="Name a new spreadsheet and choose who it belongs to." page="sheets">
    <form class="make" action="{{ route('spreadsheets.store') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <label class="sr" for="name">Name</label>
        <input class="title" type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="255" autofocus autocomplete="off" placeholder="Give it a name">
        @error('name')<p class="error">{{ $message }}</p>@enderror

        <div class="rows form">
            <div>
                <label for="team_id">Belongs to</label>
                <div>
                    <select id="team_id" name="team_id">
                        <option value="">You</option>
                        @foreach (auth()->user()->allTeams() as $team)
                            <option value="{{ $team->id }}" @selected(old('team_id') == $team->id)>{{ $team->name }}</option>
                        @endforeach
                    </select>
                    @error('team_id')<p class="error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="deeds">
            <button class="button" type="submit">Create spreadsheet</button>
            <a class="button quiet" href="{{ route('dashboard') }}">Cancel</a>
        </div>

        <section class="starts" aria-labelledby="idea-title">
            <h2 id="idea-title">Start from an idea</h2>
            <p>Say what it is for, such as a weekly fuel log for six trucks.</p>
            <div class="start-line">
                <label class="sr" for="idea">What the spreadsheet is for</label>
                <input id="idea" name="idea" maxlength="500" value="{{ old('idea') }}" autocomplete="off">
                <button class="button" type="submit" formaction="{{ route('spreadsheets.from-idea') }}" formnovalidate>Find a start</button>
            </div>
            @error('idea')<p class="error">{{ $message }}</p>@enderror
            @if ($ideaSaid)
                <p class="start-said">{{ $ideaSaid }}</p>
                @if ($closest === [])
                    <p class="start-said">None of them is close. Start with a name above, or look through the list below.</p>
                @endif
                <ul>
                    @foreach ($closest as $template)
                        <li>
                            <button type="submit" name="template" value="{{ $template['key'] }}" formaction="{{ route('spreadsheets.from-template') }}" formnovalidate>
                                <b>{{ $template['title'] }}</b>
                                <span>{{ $template['summary'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="starts" aria-labelledby="file-title">
            <h2 id="file-title">Start from a file</h2>
            <p>An Excel, ODS, CSV or TSV file up to 10 MB. Every sheet comes across, and you will be told about anything that could not.</p>
            <div class="start-line">
                <label class="sr" for="file">The file to start from</label>
                <input id="file" type="file" name="file" accept=".csv,.tsv,.xlsx,.xls,.ods">
                <button class="button" type="submit" formaction="{{ route('spreadsheets.from-file') }}" formnovalidate>Import it</button>
            </div>
            @error('file')<p class="error">{{ $message }}</p>@enderror
        </section>

        <section class="starts" aria-labelledby="starts-title">
            <h2 id="starts-title">Or start from a template</h2>
            <p>Working formulas, allowed values and summaries, ready for your figures. Nothing in them is made up.</p>
            @foreach ($groups as $group => $templates)
                <h3>{{ $group }}</h3>
                <ul>
                    @foreach ($templates as $template)
                        <li>
                            <button type="submit" name="template" value="{{ $template['key'] }}" formaction="{{ route('spreadsheets.from-template') }}" formnovalidate>
                                <b>{{ $template['title'] }}</b>
                                <span>{{ $template['summary'] }}</span>
                                <small>{{ collect($template['sheets'])->pluck('name')->implode(', ') }}</small>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </section>
    </form>
</x-dot-layout>
