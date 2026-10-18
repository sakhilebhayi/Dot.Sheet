<?php

namespace App\Livewire;

use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Services\AiService;
use App\Services\StructureVersion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class AiAnalysisPanel extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public $spreadsheet_id = null;

    public $selected_range = '';

    // Tab management
    public $active_tab = 'insights'; // insights, cleaning, charts, sentiment, ocr, workflows

    // Analysis data
    public $insights = [];

    public $cleaning_suggestions = [];

    public $chart_recommendations = [];

    public $sentiment_result = [];

    // OCR state
    public $ocrImage;

    public $ocrText = '';

    // Automated workflows
    public $workflow_name = '';

    public $workflow_column = 'A';

    public $workflow_operator = '<';

    public $workflow_value = '10';

    public $workflow_action = 'email_owner';

    public $workflow_notify_email = true;

    public $workflow_notify_database = false;

    public $workflow_rules = [];

    // Loading & error states
    public $loading = false;

    public $error = '';

    public $success_message = '';

    protected ?AiService $ai_service = null;

    protected $listeners = [
        'range-selected' => 'analyzeRange',
        'structure-synced' => 'syncStructureVersion',
    ];

    /**
     * The structure version (see StructureVersion) the editor's grid was at when this panel
     * last heard from it. Locked: the browser may not set it.
     */
    #[Locked]
    public int $structureVersion = 0;

    public function mount($spreadsheet_id = null)
    {
        if ($spreadsheet_id) {
            $this->authorize('view', Spreadsheet::findOrFail($spreadsheet_id));
        }

        $this->spreadsheet_id = $spreadsheet_id;
        $this->structureVersion = $spreadsheet_id ? StructureVersion::current((int) $spreadsheet_id) : 0;
        $this->ai_service = new AiService;
        $this->loadWorkflowRules();
    }

    /**
     * The editor reloaded and says so (event structure-synced): take the stored version. Nothing the
     * browser sends is used.
     */
    public function syncStructureVersion(): void
    {
        $this->structureVersion = $this->spreadsheet_id ? StructureVersion::current((int) $this->spreadsheet_id) : 0;
    }

    public function hydrate(): void
    {
        $this->ai_service = new AiService;
    }

    public function render()
    {
        return view('livewire.ai-analysis-panel');
    }

    /**
     * Analyze a selected data range
     */
    public function analyzeRange($range, $data = null)
    {
        $this->selected_range = strtoupper((string) $range);

        if (! $this->spreadsheet_id) {
            $this->error = 'No spreadsheet selected';

            return;
        }

        $this->loading = true;
        $this->error = '';
        $this->success_message = '';

        try {
            $rangeValues = is_array($data) && ! empty($data) ? $data : $this->getRangeValues($this->selected_range);
            if (empty($rangeValues)) {
                $this->error = 'No cells found in selected range';

                return;
            }

            $stats = $this->calculateRangeStatistics($rangeValues);

            // Use AI to generate insights
            $insights_result = $this->ai_service->analyzeData(
                [
                    'range' => $this->selected_range,
                    'stats' => $stats,
                    'sample' => array_slice($rangeValues, 0, 100),
                ],
                'Summarize key trends and risks in this range.'
            );

            if ($insights_result['success']) {
                $this->insights = $this->parseInsights($insights_result['response']);
            } else {
                $this->error = 'Failed to generate insights';
            }

            // Generate cleaning suggestions
            $this->generateCleaningSuggestions($stats);

            // Generate chart recommendations
            $recommendations = $this->ai_service->recommendChart([
                'range' => $this->selected_range,
                'stats' => $stats,
            ]);

            if ($recommendations['success']) {
                $this->chart_recommendations = $this->parseChartRecommendations($recommendations['response']);
            }

            $sentiment = $this->ai_service->analyzeSentiment(array_map(static fn ($v) => (string) $v, $rangeValues));
            $this->sentiment_result = [
                'label' => $sentiment['label'] ?? 'neutral',
                'score' => $sentiment['score'] ?? 0,
                'samples' => $sentiment['samples'] ?? count($rangeValues),
            ];

            $this->success_message = 'Analysis complete!';
            $this->active_tab = 'insights';

        } catch (\Exception $e) {
            $this->error = 'Analysis failed: '.$e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    public function analyzeSelectedRange(): void
    {
        $this->analyzeRange($this->selected_range);
    }

    /**
     * Calculate basic statistics for a data range
     */
    protected function calculateRangeStatistics($data)
    {
        if (empty($data)) {
            return [];
        }

        $values = array_filter($data, function ($v) {
            return is_numeric($v);
        });

        if (empty($values)) {
            return [
                'type' => 'non-numeric',
                'count' => count($data),
                'unique' => count(array_unique($data)),
            ];
        }

        return [
            'type' => 'numeric',
            'count' => count($values),
            'sum' => array_sum($values),
            'average' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            'unique' => count(array_unique($values)),
            'null_count' => count($data) - count($values),
        ];
    }

    /**
     * Generate data cleaning suggestions
     */
    protected function generateCleaningSuggestions($stats)
    {
        $suggestions = [];

        // Detect duplicates
        if (isset($stats['unique']) && isset($stats['count'])) {
            if ($stats['unique'] < $stats['count'] * 0.5) {
                $suggestions[] = [
                    'issue' => 'High duplicate rate',
                    'description' => 'This range has many duplicate values ('.
                                   round((1 - $stats['unique'] / $stats['count']) * 100, 1).'%)',
                    'action' => 'Remove duplicates',
                ];
            }
        }

        // Detect missing values
        if (isset($stats['null_count']) && $stats['null_count'] > 0) {
            $suggestions[] = [
                'issue' => 'Missing values',
                'description' => 'Found '.$stats['null_count'].' empty cells in this range',
                'action' => 'Fill or remove empty values',
            ];
        }

        // Detect outliers in numeric data
        if (isset($stats['average']) && isset($stats['min']) && isset($stats['max'])) {
            $range = $stats['max'] - $stats['min'];
            $threshold = $stats['average'] + (2 * ($range / 4));
            if ($stats['max'] > $threshold) {
                $suggestions[] = [
                    'issue' => 'Potential outliers',
                    'description' => 'Maximum value ('.$stats['max'].') may be an outlier',
                    'action' => 'Review extreme values',
                ];
            }
        }

        $rangeValues = $this->getRangeValues($this->selected_range);
        if (! empty($rangeValues)) {
            $local = $this->ai_service->detectDataCleaningIssues($rangeValues);
            foreach ($local['issues'] ?? [] as $issue) {
                $suggestions[] = [
                    'issue' => $issue['issue'],
                    'description' => $issue['detail'],
                    'action' => 'Review issue',
                ];
            }
        }

        $this->cleaning_suggestions = $suggestions;
    }

    /**
     * Parse AI insights response
     */
    protected function parseInsights($response)
    {
        // Simple parsing - can be enhanced
        return [
            'summary' => $response,
            'generated_at' => now()->format('H:i:s'),
        ];
    }

    /**
     * Parse chart recommendations response
     */
    protected function parseChartRecommendations($response)
    {
        // Extract chart types from AI response
        $chart_types = ['bar', 'line', 'pie', 'scatter', 'area'];
        $recommendations = [];

        foreach ($chart_types as $type) {
            if (stripos($response, $type) !== false) {
                $recommendations[] = [
                    'type' => $type,
                    'reason' => 'Suitable for this data type',
                ];
            }
        }

        return $recommendations ?: [['type' => 'bar', 'reason' => 'Default recommendation']];
    }

    /**
     * Clean data in selected range
     */
    public function cleanData()
    {
        if (! $this->selected_range) {
            $this->error = 'Select a range before cleaning data';

            return;
        }

        $this->authorize('update', Spreadsheet::findOrFail($this->spreadsheet_id));

        $this->loading = true;

        try {
            [$startRow, $endRow, $startCol, $endCol] = $this->parseRangeBounds($this->selected_range);

            $updated = 0;

            // Read and write under one lock, after checking the structure version: rows or columns
            // that moved since the editor loaded mean the range the person sees is not where the data is.
            $written = StructureVersion::writeIfCurrent((int) $this->spreadsheet_id, $this->structureVersion, function () use ($startRow, $endRow, $startCol, $endCol, &$updated) {
                $cells = Cell::where('spreadsheet_id', $this->spreadsheet_id)
                    ->whereBetween('row_index', [$startRow, $endRow])
                    ->whereBetween('col_index', [$startCol, $endCol])
                    ->get();

                foreach ($cells as $cell) {
                    if (! is_string($cell->raw_value)) {
                        continue;
                    }

                    $trimmed = $this->ai_service->normalizeCellTextForCleaning($cell->raw_value);
                    if ($trimmed === $cell->raw_value) {
                        continue;
                    }

                    $cell->raw_value = $trimmed;
                    $cell->updated_at = now();
                    $cell->save();
                    $updated++;
                }
            });

            if (! $written) {
                $this->loading = false;
                $this->dispatch('structure-stale');

                return;
            }

            $this->success_message = $updated > 0
                ? "Applied {$updated} cleaning fixes (trim/normalize spacing)."
                : 'No automatic cleaning fixes were required.';

            $this->dispatch('external-cell-updated');
        } catch (\Exception $e) {
            $this->error = 'Error: '.$e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    public function runSentimentAnalysis(): void
    {
        if (! $this->selected_range) {
            $this->error = 'Select a range before running sentiment analysis';

            return;
        }

        $values = array_map(static fn ($v) => (string) $v, $this->getRangeValues($this->selected_range));
        $result = $this->ai_service->analyzeSentiment($values);

        if (! $result['success']) {
            $this->error = $result['error'] ?? 'Sentiment analysis failed';

            return;
        }

        $this->sentiment_result = [
            'label' => $result['label'],
            'score' => $result['score'],
            'samples' => $result['samples'],
        ];
        $this->success_message = 'Sentiment analysis complete.';
        $this->active_tab = 'sentiment';
    }

    public function extractOcrText(): void
    {
        $this->validate([
            'ocrImage' => 'required|image|max:5120',
        ]);

        $result = $this->ai_service->extractTextFromImage($this->ocrImage->getRealPath());
        if (! $result['success']) {
            $this->error = $result['error'] ?? 'OCR failed';

            return;
        }

        $this->ocrText = trim((string) ($result['response'] ?? ''));
        $this->success_message = 'OCR extraction complete.';
        $this->active_tab = 'ocr';
    }

    public function insertOcrTextIntoSelectedCell(): void
    {
        if (! $this->ocrText) {
            $this->error = 'No OCR text to insert';

            return;
        }

        $this->dispatch('insert-text-at-selected-cell', [
            'text' => $this->ocrText,
        ]);

        $this->success_message = 'OCR text queued for insertion into selected cell.';
    }

    public function addWorkflowRule(): void
    {
        $this->validate([
            'workflow_name' => 'required|string|min:2|max:80',
            'workflow_column' => ['required', 'regex:/^[A-Z]{1,3}$/'],
            'workflow_operator' => 'required|in:<,<=,>,>=,==,!=',
            'workflow_value' => 'required|string|max:50',
            'workflow_action' => 'required|in:email_owner,highlight_cell',
            'workflow_notify_email' => 'boolean',
            'workflow_notify_database' => 'boolean',
        ]);

        $notifyChannels = [];
        if ($this->workflow_notify_email) {
            $notifyChannels[] = 'email';
        }
        if ($this->workflow_notify_database) {
            $notifyChannels[] = 'database';
        }

        $this->workflow_rules = collect($this->workflow_rules)
            ->prepend([
                'id' => (string) str()->uuid(),
                'name' => $this->workflow_name,
                'column' => strtoupper($this->workflow_column),
                'operator' => $this->workflow_operator,
                'value' => $this->workflow_value,
                'action' => $this->workflow_action,
                'notify_channels' => $notifyChannels,
                'enabled' => true,
                'created_at' => now()->toISOString(),
            ])
            ->values()
            ->toArray();

        $this->saveWorkflowRules();
        $this->workflow_name = '';
        $this->workflow_notify_email = true;
        $this->workflow_notify_database = false;
        $this->success_message = 'Workflow rule saved.';
    }

    public function deleteWorkflowRule(string $id): void
    {
        $this->workflow_rules = collect($this->workflow_rules)
            ->reject(fn ($rule) => ($rule['id'] ?? null) === $id)
            ->values()
            ->toArray();

        $this->saveWorkflowRules();
        $this->success_message = 'Workflow rule deleted.';
    }

    /**
     * Generate chart based on recommendation
     */
    public function generateChart($chart_type)
    {
        $this->dispatch('generate-chart', [
            'type' => $chart_type,
            'range' => $this->selected_range,
            'spreadsheet_id' => $this->spreadsheet_id,
        ]);

        $this->success_message = "Chart generation initiated for {$chart_type} chart";
    }

    /**
     * Switch analysis tab
     */
    public function switchTab($tab)
    {
        if (in_array($tab, ['insights', 'cleaning', 'charts', 'sentiment', 'ocr', 'workflows'])) {
            $this->active_tab = $tab;
        }
    }

    protected function loadWorkflowRules(): void
    {
        if (! $this->spreadsheet_id) {
            return;
        }

        $spreadsheet = Spreadsheet::find($this->spreadsheet_id);
        if (! $spreadsheet || ! Auth::user()?->can('view', $spreadsheet)) {
            return;
        }

        $settings = $this->normalizeArrayValue($spreadsheet->settings ?? []);
        $rules = $this->normalizeArrayValue($settings['ai_workflows'] ?? []);
        $this->workflow_rules = collect($rules)
            ->map(function ($rule) {
                $rule = $this->normalizeArrayValue($rule);
                if (empty($rule)) {
                    return null;
                }

                if (! isset($rule['notify_channels']) || ! is_array($rule['notify_channels'])) {
                    $rule['notify_channels'] = ['email'];
                }

                return $rule;
            })
            ->filter(fn ($rule) => is_array($rule) && ! empty($rule))
            ->values()
            ->toArray();
    }

    protected function saveWorkflowRules(): void
    {
        $spreadsheet = Spreadsheet::find($this->spreadsheet_id);
        if (! $spreadsheet || ! Auth::user()?->can('update', $spreadsheet)) {
            $this->error = 'You are not allowed to edit workflows.';

            return;
        }

        $rules = $this->workflow_rules;

        // Merge one key into the stored settings under the write lock.
        Spreadsheet::updateSettings((int) $spreadsheet->id, function (array $settings) use ($rules) {
            $settings['ai_workflows'] = $rules;

            return $settings;
        });
    }

    protected function normalizeArrayValue(mixed $value): array
    {
        $normalized = $this->normalizeForLivewire($value);

        return is_array($normalized) ? $normalized : [];
    }

    protected function normalizeForLivewire(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeForLivewire($item);
            }

            return $normalized;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value)) {
            return $this->normalizeForLivewire((array) $value);
        }

        return $value;
    }

    protected function getRangeValues(string $range): array
    {
        if (! $range) {
            return [];
        }

        try {
            [$startRow, $endRow, $startCol, $endCol] = $this->parseRangeBounds($range);
        } catch (\InvalidArgumentException) {
            return [];
        }

        $cells = Cell::where('spreadsheet_id', $this->spreadsheet_id)
            ->whereBetween('row_index', [$startRow, $endRow])
            ->whereBetween('col_index', [$startCol, $endCol])
            ->orderBy('row_index')
            ->orderBy('col_index')
            ->get(['raw_value', 'computed_value']);

        return $cells->map(function ($cell) {
            return $cell->computed_value ?? $cell->raw_value ?? '';
        })->toArray();
    }

    protected function parseRangeBounds(string $range): array
    {
        $range = strtoupper(trim($range));

        if (preg_match('/^([A-Z]{1,3})(\d+)$/', $range, $single)) {
            $col = $this->colLetterToIndex($single[1]);
            $row = ((int) $single[2]) - 1;

            return [$row, $row, $col, $col];
        }

        if (! preg_match('/^([A-Z]{1,3})(\d+):([A-Z]{1,3})(\d+)$/', $range, $matches)) {
            throw new \InvalidArgumentException('Invalid range format. Use A1:B12');
        }

        $startCol = $this->colLetterToIndex($matches[1]);
        $startRow = ((int) $matches[2]) - 1;
        $endCol = $this->colLetterToIndex($matches[3]);
        $endRow = ((int) $matches[4]) - 1;

        if ($startRow > $endRow) {
            [$startRow, $endRow] = [$endRow, $startRow];
        }
        if ($startCol > $endCol) {
            [$startCol, $endCol] = [$endCol, $startCol];
        }

        return [$startRow, $endRow, $startCol, $endCol];
    }

    protected function colLetterToIndex(string $letter): int
    {
        $index = 0;
        for ($i = 0; $i < strlen($letter); $i++) {
            $index = $index * 26 + (ord($letter[$i]) - 64);
        }

        return $index - 1;
    }
}
