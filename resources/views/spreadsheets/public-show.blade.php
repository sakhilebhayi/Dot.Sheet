<x-app-layout>
<div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <div class="max-w-7xl mx-auto px-4 py-6">
        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm mb-4 p-4">
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">{{ $spreadsheet->name }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Public view-only link</p>
            @if($spreadsheet->public_expires_at)
                <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">Expires {{ $spreadsheet->public_expires_at->diffForHumans() }}</p>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm overflow-auto">
            @if(empty($grid))
                <div class="p-8 text-sm text-gray-500 dark:text-gray-400">This spreadsheet has no data to display.</div>
            @else
                <table class="min-w-full border-collapse">
                    <thead>
                        <tr>
                            <th class="sticky top-0 bg-gray-100 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 px-2 py-1 text-xs text-gray-600 dark:text-gray-300">#</th>
                            @for($c = $startCol; $c <= $endCol; $c++)
                                <th class="sticky top-0 bg-gray-100 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 px-2 py-1 text-xs text-gray-600 dark:text-gray-300 min-w-24">
                                    {{ \App\Livewire\ShowSpreadsheet::colIndexToLetter($c) }}
                                </th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($grid as $rowIndex => $row)
                            <tr>
                                <td class="bg-gray-100 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 px-2 py-1 text-xs text-gray-600 dark:text-gray-300">{{ $rowIndex + 1 }}</td>
                                @foreach($row as $value)
                                    <td class="border border-gray-200 dark:border-gray-600 px-2 py-1 text-sm text-gray-800 dark:text-gray-200 whitespace-nowrap">{{ $value }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
</x-app-layout>
