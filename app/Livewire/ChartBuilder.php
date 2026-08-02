<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Spreadsheet;
use App\Models\Cell;
use App\Models\ChartConfig;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ChartBuilder extends Component
{
    use AuthorizesRequests;

    public $spreadsheet_id;

    // Form state (modal)
    public $showModal = false;
    public $title       = 'My Chart';
    public $type        = 'bar';
    public $data_range  = '';
    public $labels_range = '';

    // Saved charts list
    public $charts = [];

    // Chart types available
    public $chartTypes = [
        'bar'       => 'Bar Chart',
        'line'      => 'Line Chart',
        'pie'       => 'Pie Chart',
        'doughnut'  => 'Doughnut Chart',
        'scatter'   => 'Scatter Plot',
        'area'      => 'Area Chart',
    ];

    protected $rules = [
        'title'        => 'required|string|max:100',
        'type'         => 'required|in:bar,line,pie,doughnut,scatter,area',
        'data_range'   => ['required', 'regex:/^[A-Z]+\d+:[A-Z]+\d+$/i'],
        'labels_range' => ['nullable', 'regex:/^[A-Z]+\d+:[A-Z]+\d+$/i'],
    ];

    public function mount($spreadsheet_id)
    {
        $this->authorize('view', Spreadsheet::findOrFail($spreadsheet_id));

        $this->spreadsheet_id = $spreadsheet_id;
        $this->loadCharts();
    }

    public function render()
    {
        return view('livewire.chart-builder');
    }

    public function loadCharts()
    {
        $this->charts = ChartConfig::where('spreadsheet_id', $this->spreadsheet_id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function openModal()
    {
        $this->reset(['title', 'type', 'data_range', 'labels_range']);
        $this->title = 'My Chart';
        $this->type  = 'bar';
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
            'title'          => $this->title,
            'type'           => $this->type,
            'data_range'     => strtoupper($this->data_range),
            'labels_range'   => $this->labels_range ? strtoupper($this->labels_range) : null,
            'options'        => [],
        ]);

        $this->loadCharts();
        $this->showModal = false;

        // Dispatch event to render the chart in the browser
        $this->dispatch('render-chart', [
            'id'    => $chart->id,
            'title' => $chart->title,
            'type'  => $chart->type === 'area' ? 'line' : $chart->type, // Chart.js uses 'line' for area
            'fill'  => $chart->type === 'area',
            'data'  => $chartData,
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
            ->delete();

        $this->loadCharts();
        $this->dispatch('chart-deleted', ['id' => $chartId]);
    }

    /**
     * Re-render an existing chart
     */
    public function renderChart($chartId)
    {
        $chart = ChartConfig::findOrFail($chartId);
        $chartData = $this->getChartData($chart->data_range, $chart->labels_range);

        $this->dispatch('render-chart', [
            'id'    => $chart->id,
            'title' => $chart->title,
            'type'  => $chart->type === 'area' ? 'line' : $chart->type,
            'fill'  => $chart->type === 'area',
            'data'  => $chartData,
        ]);
    }

    /**
     * Parse a range like "A1:C5" and return Chart.js dataset structure
     */
    protected function getChartData(string $dataRange, ?string $labelsRange): array
    {
        [$startRef, $endRef] = explode(':', strtoupper($dataRange));
        [$startCol, $startRow] = $this->parseRef($startRef);
        [$endCol, $endRow]   = $this->parseRef($endRef);

        // Fetch cells from DB for data range
        $cells = Cell::where('spreadsheet_id', $this->spreadsheet_id)
            ->whereBetween('row_index', [$startRow, $endRow])
            ->whereBetween('col_index', [$startCol, $endCol])
            ->get()
            ->groupBy('col_index');

        // Build labels (row numbers or from labels_range)
        $labels = [];
        if ($labelsRange) {
            [$lStart, $lEnd] = explode(':', strtoupper($labelsRange));
            [$lCol, $lStartRow] = $this->parseRef($lStart);
            [$lColEnd, $lEndRow] = $this->parseRef($lEnd);

            $labelCells = Cell::where('spreadsheet_id', $this->spreadsheet_id)
                ->whereBetween('row_index', [$lStartRow, $lEndRow])
                ->where('col_index', $lCol)
                ->orderBy('row_index')
                ->pluck('computed_value')
                ->toArray();

            $labels = $labelCells;
        } else {
            for ($r = $startRow; $r <= $endRow; $r++) {
                $labels[] = 'Row ' . $r;
            }
        }

        // Build datasets per column
        $datasets = [];
        $colors   = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];

        foreach ($cells as $colIdx => $colCells) {
            $colLetter  = $this->colIndexToLetter($colIdx);
            $color      = $colors[($colIdx - $startCol) % count($colors)];
            $values     = [];

            foreach (range($startRow, $endRow) as $row) {
                $cell   = $colCells->firstWhere('row_index', $row);
                $values[] = is_numeric($cell?->computed_value) ? (float) $cell->computed_value : 0;
            }

            $datasets[] = [
                'label'           => $colLetter,
                'data'            => $values,
                'backgroundColor' => $color . '80',
                'borderColor'     => $color,
                'borderWidth'     => 2,
            ];
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    /**
     * Parse a cell reference like "B3" into [colIndex, rowIndex]
     */
    protected function parseRef(string $ref): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
        $col = 0;
        foreach (str_split($m[1]) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }
        return [$col, (int) $m[2]];
    }

    protected function colIndexToLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $rem    = ($index - 1) % 26;
            $letter = chr(65 + $rem) . $letter;
            $index  = (int)(($index - 1) / 26);
        }
        return $letter;
    }
}
