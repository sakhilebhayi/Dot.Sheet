<?php

namespace App\Livewire;

use App\Models\Spreadsheet;
use App\Services\AiService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AiNaturalLanguageQuery extends Component
{
    public $spreadsheet_id = null;

    public $query = '';

    // Conversation history
    public $conversation = [];

    // Loading & state
    public $loading = false;

    public $error = '';

    public $last_action = null;

    // Query suggestions
    public $suggestions = [
        'What is the sum of column A?',
        'Show me the average of sales data',
        'Find rows with values above 100',
        'Sort data by date',
        'Calculate year-over-year growth',
    ];

    protected ?AiService $ai_service = null;

    public function mount($spreadsheet_id = null)
    {
        $this->spreadsheet_id = $spreadsheet_id;
        $this->ai_service = new AiService;

        // Add welcome message
        $this->conversation[] = [
            'role' => 'assistant',
            'message' => 'Hi! I can help you analyze your spreadsheet data. Ask me questions like "What is the average of column A?" or "Find all rows where sales exceed 100".',
            'timestamp' => now()->toISOString(),
        ];
    }

    public function hydrate(): void
    {
        $this->ai_service = new AiService;
    }

    public function render()
    {
        return view('livewire.ai-natural-language-query');
    }

    /**
     * Validate and submit a query
     */
    public function submitQuery()
    {
        if (! $this->query || strlen(trim($this->query)) < 3) {
            $this->error = 'Query must be at least 3 characters';

            return;
        }

        if (! $this->spreadsheet_id) {
            $this->error = 'No spreadsheet selected';

            return;
        }

        // Add user query to conversation
        $this->conversation[] = [
            'role' => 'user',
            'message' => $this->query,
            'timestamp' => now()->toISOString(),
        ];

        $this->loading = true;
        $this->error = '';

        try {
            $spreadsheet = Spreadsheet::findOrFail($this->spreadsheet_id);

            // Check authorization
            if (! Auth::user()->can('view', $spreadsheet)) {
                throw new \Exception('Unauthorized');
            }

            // Get spreadsheet context
            $context = $this->getSpreadsheetContext($spreadsheet);

            // Process query with AI
            $result = $this->ai_service->processNaturalLanguageQuery(
                $this->query,
                $context
            );

            if (! $result['success']) {
                throw new \Exception($result['error']);
            }

            // Add AI response to conversation
            $this->conversation[] = [
                'role' => 'assistant',
                'message' => $result['response'],
                'timestamp' => now()->toISOString(),
                'action' => $result['action'] ?? null,
            ];

            // Store the last action
            if (isset($result['action'])) {
                $this->last_action = $result['action'];
                $this->handleQueryAction($result['action']);
            }

        } catch (\Exception $e) {
            $this->error = 'Query failed: '.$e->getMessage();

            // Still add to conversation
            $this->conversation[] = [
                'role' => 'assistant',
                'message' => 'Sorry, I encountered an error processing your query: '.$e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        } finally {
            $this->query = '';
            $this->loading = false;
            // Scroll to bottom
            $this->dispatch('scroll-to-bottom');
        }
    }

    /**
     * Use a suggested query
     */
    public function useSuggestion($suggestion)
    {
        $this->query = $suggestion;
    }

    /**
     * Get spreadsheet data context for AI
     */
    protected function getSpreadsheetContext($spreadsheet)
    {
        // Get column headers (first row)
        $headers = $spreadsheet->cells()
            ->where('row_index', 1)
            ->pluck('computed_value', 'col_index')
            ->toArray();

        // Get data types for each column
        $sample_data = $spreadsheet->cells()
            ->where('row_index', '<=', 10)
            ->get()
            ->groupBy('col_index')
            ->map(function ($cells) {
                return $cells->pluck('computed_value')->toArray();
            });

        return [
            'name' => $spreadsheet->name,
            'headers' => $headers ? $headers : 'No headers found',
            'row_count' => $spreadsheet->cells()->max('row_index') ?? 0,
            'col_count' => $spreadsheet->cells()->max('col_index') ?? 0,
            'sample_data' => $sample_data,
        ];
    }

    /**
     * Handle actions suggested by AI
     */
    protected function handleQueryAction($action)
    {
        if (! isset($action['type'])) {
            return;
        }

        switch ($action['type']) {
            case 'highlight_range':
                // Emit event to highlight cells in spreadsheet
                $this->dispatch('highlight-range', [
                    'range' => $action['range'],
                    'color' => $action['color'] ?? 'yellow',
                ]);
                break;

            case 'filter':
                // Emit event to apply filter
                $this->dispatch('apply-filter', [
                    'column' => $action['column'],
                    'operator' => $action['operator'],
                    'value' => $action['value'],
                ]);
                break;

            case 'sort':
                // Emit event to sort data
                $this->dispatch('apply-sort', [
                    'column' => $action['column'],
                    'direction' => $action['direction'],
                ]);
                break;

            case 'create_formula':
                // Emit event to create formula
                $this->dispatch('create-formula', [
                    'formula' => $action['formula'],
                    'cell' => $action['cell'],
                ]);
                break;
        }
    }

    /**
     * Clear conversation history
     */
    public function clearConversation()
    {
        $this->conversation = [];
        $this->last_action = null;
        $this->query = '';
        $this->error = '';

        // Add welcome message
        $this->mount($this->spreadsheet_id);
    }

    /**
     * Provide feedback on AI response
     */
    public function provideFeedback($response_index, $helpful)
    {
        if (isset($this->conversation[$response_index])) {
            // Store feedback (can be used to improve AI responses)
            $response = $this->conversation[$response_index];

            // Add feedback indicator
            $this->conversation[$response_index]['feedback'] = $helpful ? 'positive' : 'negative';
        }
    }
}
