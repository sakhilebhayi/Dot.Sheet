{{-- Formatting Toolbar --}}
<div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-1 flex items-center gap-1 flex-wrap text-sm select-none">

    <!-- Font Size -->
    <div class="flex items-center gap-1 pr-2 border-r border-gray-200 dark:border-gray-600">
        <button wire:click="updateFontSize({{ max(6, $font_size - 1) }})"
                class="w-6 h-6 flex items-center justify-center rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-bold">−</button>
        <input type="number" wire:change="updateFontSize($event.target.value)" value="{{ $font_size }}"
               min="6" max="72"
               class="w-12 text-center border border-gray-300 dark:border-gray-600 rounded text-xs
                      bg-white dark:bg-gray-700 text-gray-900 dark:text-white py-0.5">
        <button wire:click="updateFontSize({{ min(72, $font_size + 1) }})"
                class="w-6 h-6 flex items-center justify-center rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-bold">+</button>
    </div>

    <!-- Text Formatting -->
    <div class="flex items-center gap-0.5 pr-2 border-r border-gray-200 dark:border-gray-600">
        <button wire:click="toggle('bold')" title="Bold (Ctrl+B)"
                class="w-7 h-7 flex items-center justify-center rounded font-bold
                       {{ $bold ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
            B
        </button>
        <button wire:click="toggle('italic')" title="Italic (Ctrl+I)"
                class="w-7 h-7 flex items-center justify-center rounded italic
                       {{ $italic ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
            I
        </button>
        <button wire:click="toggle('underline')" title="Underline (Ctrl+U)"
                class="w-7 h-7 flex items-center justify-center rounded underline
                       {{ $underline ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
            U
        </button>
        <button wire:click="toggle('strike')" title="Strikethrough"
                class="w-7 h-7 flex items-center justify-center rounded line-through
                       {{ $strike ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
            S
        </button>
    </div>

    <!-- Colors -->
    <div class="flex items-center gap-1 pr-2 border-r border-gray-200 dark:border-gray-600">
        <label class="flex flex-col items-center cursor-pointer" title="Font color">
            <span class="text-xs text-gray-500 dark:text-gray-400 leading-none">A</span>
            <input type="color" wire:model="font_color" wire:change="applyFormatting"
                   value="{{ $font_color }}"
                   class="w-6 h-3 rounded cursor-pointer border-0 p-0" style="height:6px">
        </label>
        <label class="flex flex-col items-center cursor-pointer" title="Background color">
            <svg class="w-4 h-4 text-gray-500 dark:text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                <path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2H4zm0 2h12v12H4V4z"/>
            </svg>
            <input type="color" wire:model="bg_color" wire:change="applyFormatting"
                   value="{{ $bg_color }}"
                   class="w-6 h-3 rounded cursor-pointer border-0 p-0" style="height:6px">
        </label>
    </div>

    <!-- Alignment -->
    <div class="flex items-center gap-0.5 pr-2 border-r border-gray-200 dark:border-gray-600">
        @foreach (['left' => 'M3 5h14M3 9h8M3 13h14M3 17h8', 'center' => 'M3 5h14M6 9h8M3 13h14M6 17h8', 'right' => 'M3 5h14M9 9h8M3 13h14M9 17h8'] as $dir => $path)
            <button wire:click="setAlign('{{ $dir }}')" title="Align {{ $dir }}"
                    class="w-7 h-7 flex items-center justify-center rounded
                           {{ $align === $dir ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 20 20">
                    <path stroke-linecap="round" d="{{ $path }}"/>
                </svg>
            </button>
        @endforeach
    </div>

    <!-- Number Format -->
    <div class="flex items-center gap-1 pr-2 border-r border-gray-200 dark:border-gray-600">
        <select wire:change="setNumberFormat($event.target.value)"
                class="text-xs border border-gray-300 dark:border-gray-600 rounded px-1 py-0.5
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
            @foreach (['general' => 'General', 'number' => '1,234.00', 'currency' => '$1,234', 'percent' => '12%', 'date' => 'Date', 'time' => 'Time', 'text' => 'Text'] as $val => $label)
                <option value="{{ $val }}" {{ $number_format === $val ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <!-- Clear Formatting -->
    <button wire:click="clearFormatting" title="Clear formatting"
            class="flex items-center gap-1 px-2 py-1 text-xs text-gray-600 dark:text-gray-400
                   hover:bg-gray-100 dark:hover:bg-gray-700 rounded border border-gray-300 dark:border-gray-600">
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
        Clear
    </button>

    <!-- Format Painter -->
    <div class="flex items-center gap-1 pl-2 border-l border-gray-200 dark:border-gray-600">
        <button wire:click="captureFormatPainter"
                class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            Pick Format
        </button>
        <button wire:click="applyFormatPainter"
                class="px-2 py-1 text-xs rounded border {{ $formatPainterActive ? 'border-blue-500 text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300' }} hover:bg-gray-100 dark:hover:bg-gray-700">
            Paint
        </button>
    </div>

    <!-- Rows / Columns -->
    <div class="flex items-center gap-1 pl-2 border-l border-gray-200 dark:border-gray-600">
        <button wire:click="insertRow" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">+Row</button>
        <button wire:click="deleteRow" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">-Row</button>
        <button wire:click="insertColumn" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">+Col</button>
        <button wire:click="deleteColumn" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">-Col</button>
        <button wire:click="toggleHideRow" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">Hide Row</button>
        <button wire:click="toggleHideColumn" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">Hide Col</button>
    </div>

    <!-- Resize -->
    <div class="flex items-center gap-1 pl-2 border-l border-gray-200 dark:border-gray-600">
        <input type="number" wire:model="row_height_input" min="18" max="120" class="w-14 text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5" placeholder="RowH">
        <button wire:click="resizeRow" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">Set Row</button>
        <input type="number" wire:model="col_width_input" min="48" max="420" class="w-14 text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5" placeholder="ColW">
        <button wire:click="resizeColumn" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">Set Col</button>
    </div>

    <!-- Validation -->
    <div class="flex items-center gap-1 pl-2 border-l border-gray-200 dark:border-gray-600">
        <select wire:model="validation_type" class="text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5">
            <option value="number">Number</option>
            <option value="text_length">Text Length</option>
            <option value="list">List</option>
        </select>
        <input type="text" wire:model="validation_min" class="w-14 text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5" placeholder="Min">
        <input type="text" wire:model="validation_max" class="w-14 text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5" placeholder="Max">
        <input type="text" wire:model="validation_items" class="w-24 text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5" placeholder="a,b,c">
        <button wire:click="addValidationRule" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">Add Validation</button>
    </div>

    <!-- Conditional Formatting -->
    <div class="flex items-center gap-1 pl-2 border-l border-gray-200 dark:border-gray-600">
        <select wire:model="conditional_target" class="text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5">
            <option value="cell">Cell</option>
            <option value="column">Column</option>
        </select>
        <select wire:model="conditional_operator" class="text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5">
            <option value=">">&gt;</option>
            <option value=">=">&gt;=</option>
            <option value="<">&lt;</option>
            <option value="<=">&lt;=</option>
            <option value="==">==</option>
            <option value="!=">!=</option>
        </select>
        <input type="text" wire:model="conditional_value" class="w-14 text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5" placeholder="Value">
        <input type="color" wire:model="conditional_color" class="w-8 h-6 rounded border-0 p-0">
        <button wire:click="addConditionalRule" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">Add Rule</button>
    </div>

    <!-- Sort & Filter -->
    <div class="flex items-center gap-1 pl-2 border-l border-gray-200 dark:border-gray-600">
        <select wire:model="sort_direction" class="text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5">
            <option value="asc">Sort Asc</option>
            <option value="desc">Sort Desc</option>
        </select>
        <button wire:click="sortSelectedColumn" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">Sort Col</button>

        <input type="text" wire:model="filter_column" class="w-10 text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5" placeholder="A">
        <select wire:model="filter_type" class="text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5">
            <option value="contains">Contains</option>
            <option value="number_between">Number Between</option>
            <option value="date_range">Date Range</option>
            <option value="color">By Color</option>
            <option value="condition">By Condition</option>
        </select>
        <select wire:model="filter_operator" class="text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5">
            <option value=">">&gt;</option>
            <option value=">=">&gt;=</option>
            <option value="<">&lt;</option>
            <option value="<=">&lt;=</option>
            <option value="==">==</option>
            <option value="!=">!=</option>
            <option value="contains">contains</option>
        </select>
        <input type="text" wire:model="filter_value" class="w-16 text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5" placeholder="Value 1">
        <input type="text" wire:model="filter_value2" class="w-16 text-xs rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-1 py-0.5" placeholder="Value 2">
        <input type="color" wire:model="filter_color" class="w-8 h-6 rounded border-0 p-0">
        <button wire:click="addFilterRule" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">Add Filter</button>
        <button wire:click="clearFilters" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">Clear Filters</button>
    </div>
</div>

