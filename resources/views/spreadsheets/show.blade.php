<x-app-layout>
    <x-slot:title>{{ $spreadsheet->name }}: Dot.Sheets</x-slot:title>

    @livewire('show-spreadsheet', ['spreadsheet_id' => $spreadsheet->id])
</x-app-layout>
