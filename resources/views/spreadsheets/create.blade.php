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
                        @foreach (auth()->user()->teams as $team)
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
    </form>
</x-dot-layout>
