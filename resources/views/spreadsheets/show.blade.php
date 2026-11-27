<x-app-layout>
    <x-slot:title>{{ $spreadsheet->name }}: Dot.Sheet</x-slot:title>

    @vite(['resources/js/classic.js'])
    <x-sheet-header :title="$spreadsheet->name" editor />
    @livewire('show-spreadsheet', ['spreadsheet_id' => $spreadsheet->id])
</x-app-layout>
