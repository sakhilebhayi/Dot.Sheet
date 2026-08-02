<?php

namespace App\Livewire;

use App\Models\Spreadsheet;
use App\Services\AiService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\Attributes\On;

class AiFormulaModal extends Component
{
    use AuthorizesRequests;

    public $spreadsheet_id;
    public $selected_row;
    public $selected_col;
    public $cell_reference;
    
    public $description = '';
    public $generated_formula = null;
    public $ai_suggestion = '';
    public $loading = false;
    public $error = null;
    public $success = false;
    
    protected $ai_service;

    public function mount($spreadsheet_id, $row = 0, $col = 0)
    {
        $this->authorize('view', Spreadsheet::findOrFail($spreadsheet_id));

        $this->spreadsheet_id = $spreadsheet_id;
        $this->selected_row = $row;
        $this->selected_col = $col;
        $this->cell_reference = $this->colIndexToLetter($col) . ($row + 1);
        $this->ai_service = new AiService();
    }

    #[On('open-formula-modal')]
    public function openModal($row, $col)
    {
        $this->selected_row = $row;
        $this->selected_col = $col;
        $this->cell_reference = $this->colIndexToLetter($col) . ($row + 1);
        $this->resetForm();
        $this->dispatch('show-formula-modal');
    }

    public function generateFormula()
    {
        $this->validate([
            'description' => 'required|string|min:5|max:500',
        ]);

        $this->loading = true;
        $this->error = null;

        try {
            // Build context
            $context = [
                'cell' => $this->cell_reference,
                'available_cells' => ['A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'D1', 'D2'],
            ];

            // Generate formula using AI
            $result = $this->ai_service->generateFormula($this->description, $context);

            if (!$result['success']) {
                $this->error = $result['error'] ?? 'Failed to generate formula';
                $this->loading = false;
                return;
            }

            $this->generated_formula = $result['formula'];
            $this->ai_suggestion = "Generated formula: " . $this->generated_formula;
            
            // Store in AI prompts table
            $this->storeAiPrompt($result);

            $this->success = true;
        } catch (\Exception $e) {
            $this->error = 'Error: ' . $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    public function insertFormula()
    {
        if (!$this->generated_formula) {
            $this->error = 'No formula generated. Please generate one first.';
            return;
        }

        // Emit event to ShowSpreadsheet to insert the formula
        $this->dispatch('formula-accepted', 
            row: $this->selected_row,
            col: $this->selected_col,
            formula: $this->generated_formula
        );

        $this->resetForm();
    }

    public function resetForm()
    {
        $this->description = '';
        $this->generated_formula = null;
        $this->ai_suggestion = '';
        $this->error = null;
        $this->success = false;
    }

    protected function storeAiPrompt($result)
    {
        \App\Models\AiPrompt::create([
            'user_id' => auth()->id(),
            'spreadsheet_id' => $this->spreadsheet_id,
            'prompt' => $this->description,
            'response' => json_encode($result),
            'context' => [
                'type' => 'formula_generation',
                'cell' => $this->cell_reference,
                'formula' => $this->generated_formula,
            ],
        ]);
    }

    protected static function colIndexToLetter($index)
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26) - 1;
            if ($index < 0) break;
        }
        return $letter;
    }

    public function render()
    {
        return view('livewire.ai-formula-modal');
    }
}
