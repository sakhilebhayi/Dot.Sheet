<x-dot-layout title="New spreadsheet" description="Name a new spreadsheet and choose who it belongs to." page="sheets">
    <form class="make" action="{{ route('spreadsheets.store') }}" method="POST">
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
