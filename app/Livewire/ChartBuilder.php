<?php

namespace App\Livewire;

use App\Livewire\Concerns\ChecksSheetEditAccess;
use App\Livewire\Concerns\GuardsSheetState;
use App\Models\ChartConfig;
use App\Models\Spreadsheet;
use App\Services\SheetCharts;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ChartBuilder extends Component
{
    /** The chart colours in DESIGN.md. */
    private const COLOURS = ['#1F8A5F', '#8B3FA8', '#6E7A1E', '#0E8AA0', '#B5791A', '#B0498C'];

    use AuthorizesRequests;
    use ChecksSheetEditAccess;
    use GuardsSheetState;

    #[Locked]
    public $spreadsheet_id;

    #[Locked]
    public $sheet_tab_id;

    // Form state (modal)
    public $showModal = false;

    public $title = 'My Chart';

    public $type = 'bar';

    public $data_range = '';

    public $labels_range = '';

    // Saved charts list
    public $charts = [];

    // Chart types available
    public $chartTypes = [
        'bar' => 'Bar Chart',
        'line' => 'Line Chart',
        'pie' => 'Pie Chart',
        'doughnut' => 'Doughnut Chart',
        'scatter' => 'Scatter Plot',
        'area' => 'Area Chart',
    ];

    protected $rules = [
        'title' => 'required|string|max:100',
        'type' => 'required|in:bar,line,pie,doughnut,scatter,area',
        'data_range' => ['required', 'regex:/^[A-Z]+\d+:[A-Z]+\d+$/i'],
        'labels_range' => ['nullable', 'regex:/^[A-Z]+\d+:[A-Z]+\d+$/i'],
    ];

    protected function clientWritable(): array
    {
        return [
            'type',
            'data_range',
            'labels_range',
            'title',
        ];
    }

    public function mount($spreadsheet_id, $sheet_tab_id = null)
    {
        $this->authorize('view', Spreadsheet::findOrFail($spreadsheet_id));

        $this->spreadsheet_id = $spreadsheet_id;
        $this->sheet_tab_id = $this->ownTabId($sheet_tab_id);
        $this->loadCharts();
    }

    public function render()
    {
        return view('livewire.chart-builder', ['canEdit' => $this->canEditSheet()]);
    }

    public function loadCharts()
    {
        $this->charts = ChartConfig::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function openModal()
    {
        $this->reset(['title', 'type', 'data_range', 'labels_range']);
        $this->title = 'My Chart';
        $this->type = 'bar';
        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
    }

    /**
     * Save chart configuration and emit chart data
     */
    public function saveChart()
    {
        $this->authorize('update', Spreadsheet::findOrFail($this->spreadsheet_id));

        $this->validate();

        $chartData = $this->getChartData($this->data_range, $this->labels_range);

        $chart = ChartConfig::create([
            'spreadsheet_id' => $this->spreadsheet_id,
            'sheet_tab_id' => $this->sheet_tab_id,
            'title' => $this->title,
            'type' => $this->type,
            'data_range' => strtoupper($this->data_range),
            'labels_range' => $this->labels_range ? strtoupper($this->labels_range) : null,
            'options' => [],
        ]);

        $this->loadCharts();
        $this->showModal = false;

        // Dispatch event to render the chart in the browser
        $this->dispatch('render-chart', [
            'id' => $chart->id,
            'title' => $chart->title,
            'type' => $chart->type === 'area' ? 'line' : $chart->type, // Chart.js uses 'line' for area
            'fill' => $chart->type === 'area',
            'data' => $chartData,
        ]);
    }

    /**
     * Delete a chart config
     */
    public function deleteChart($chartId)
    {
        $this->authorize('update', Spreadsheet::findOrFail($this->spreadsheet_id));

        ChartConfig::where('id', $chartId)
            ->where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->delete();

        $this->loadCharts();
        $this->dispatch('chart-deleted', ['id' => $chartId]);
    }

    /**
     * Re-render an existing chart
     */
    public function renderChart($chartId)
    {
        $chart = ChartConfig::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->findOrFail($chartId);
        $chartData = $this->getChartData($chart->data_range, $chart->labels_range);

        $this->dispatch('render-chart', [
            'id' => $chart->id,
            'title' => $chart->title,
            'type' => $chart->type === 'area' ? 'line' : $chart->type,
            'fill' => $chart->type === 'area',
            'data' => $chartData,
        ]);
    }

    /**
     * What a range holds, as Chart.js wants it.
     */
    protected function getChartData(string $dataRange, ?string $labelsRange): array
    {
        $read = app(SheetCharts::class)->read((int) $this->sheet_tab_id, $dataRange, $labelsRange);

        $datasets = [];
        foreach ($read['series'] as $i => $series) {
            $colour = self::COLOURS[$i % count(self::COLOURS)];
            $datasets[] = ['label' => $series['name'], 'data' => $series['values'], 'backgroundColor' => $colour.'80', 'borderColor' => $colour, 'borderWidth' => 2];
        }

        return ['labels' => $read['labels'], 'datasets' => $datasets];
    }

    /**
     * Parse a cell reference like "B3" into [colIndex, rowIndex], 0-based
     * to match how Cell.row_index/col_index are actually stored
     * everywhere else in the app (ShowSpreadsheet::saveCell, NamedRange,
     * FormulaEvaluatorService) -- "A1" is [0, 0], not [1, 1]. This used to
     * be 1-based here only, so every chart's data range silently queried
     * one row/column past what the user actually selected.
     */
    protected function parseRef(string $ref): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
        $col = 0;
        foreach (str_split($m[1]) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }

        return [$col - 1, ((int) $m[2]) - 1];
    }
}
