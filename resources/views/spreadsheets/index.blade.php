<x-app-layout>
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h2 class="text-3xl font-bold text-gray-900 dark:text-white">My Spreadsheets</h2>
                <p class="text-gray-600 dark:text-gray-400 mt-2">Create and manage your spreadsheets</p>
            </div>
            <a href="{{ route('spreadsheets.create') }}"
               class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                + New Spreadsheet
            </a>
        </div>

        <!-- Spreadsheets Grid -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 p-6">
                @forelse ($spreadsheets as $spreadsheet)
                    <a href="{{ route('spreadsheets.show', $spreadsheet) }}"
                       class="flex flex-col p-4 border border-gray-200 dark:border-gray-700 rounded-lg hover:shadow-lg transition">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">
                            {{ $spreadsheet->name }}
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            @if ($spreadsheet->team)
                                Team: {{ $spreadsheet->team->name }}
                            @else
                                Personal
                            @endif
                        </p>
                        <div class="text-xs text-gray-500 dark:text-gray-500 mt-auto">
                            Updated {{ $spreadsheet->updated_at->diffForHumans() }}
                        </div>
                    </a>
                @empty
                    <div class="col-span-full text-center py-12">
                        <p class="text-gray-600 dark:text-gray-400 mb-4">
                            No spreadsheets yet. Create your first one!
                        </p>
                        <a href="{{ route('spreadsheets.create') }}"
                           class="text-blue-600 hover:text-blue-700 font-medium">
                            Create a Spreadsheet
                        </a>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
</x-app-layout>
