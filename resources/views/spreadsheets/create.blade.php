<x-app-layout>
<div class="py-12">
    <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-8">
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-8">Create New Spreadsheet</h2>

            <form action="{{ route('spreadsheets.store') }}" method="POST" class="space-y-6">
                @csrf

                <!-- Name Field -->
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Spreadsheet Name
                    </label>
                    <input type="text" id="name" name="name" required
                           class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
                                  bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                  focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="e.g., Sales Report Q1">
                    @error('name')
                        <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Team Selection -->
                <div>
                    <label for="team_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Team (Optional)
                    </label>
                    <select id="team_id" name="team_id"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
                                   bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                   focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">Personal</option>
                        @foreach (auth()->user()->teams as $team)
                            <option value="{{ $team->id }}">{{ $team->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
                        Leave empty for personal spreadsheet
                    </p>
                </div>

                <!-- Buttons -->
                <div class="flex gap-4 pt-6">
                    <button type="submit"
                            class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                        Create Spreadsheet
                    </button>
                    <a href="{{ route('spreadsheets.index') }}"
                       class="px-6 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300
                              rounded-lg font-medium hover:bg-gray-50 dark:hover:bg-gray-700">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
</x-app-layout>
