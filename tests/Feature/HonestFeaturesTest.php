<?php

namespace Tests\Feature;

use App\Livewire\AiAnalysisPanel;
use App\Livewire\AiFormulaModal;
use App\Livewire\AiNaturalLanguageQuery;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** EA-3: the product says what it does, and nothing pretends to be what it is not (F-01, F-02, F-07). */
class HonestFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function sheet(User $owner): Spreadsheet
    {
        return Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => null, 'name' => 'Budget']);
    }

    public function test_the_landing_page_makes_no_claim_the_product_does_not_keep(): void
    {
        $page = $this->get('/')->assertOk();

        foreach (['real time', 'Realtime', 'presence channel', 'OCR', 'sentiment', 'client-side parser'] as $claim) {
            $page->assertDontSee($claim, false);
        }
        $page->assertSee('within a few seconds', false)->assertSee('which a business switches on', false);
    }

    public function test_the_classic_editor_does_not_show_canned_ai_answers_as_real_ones(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheet($owner);
        $tab = $sheet->sheetTabs()->first();
        $this->actingAs($owner);

        Livewire::test(AiFormulaModal::class, ['spreadsheet_id' => $sheet->id, 'sheet_tab_id' => $tab->id])
            ->set('description', 'add up the first column')
            ->call('generateFormula')
            ->assertSet('generated_formula', fn ($formula) => blank($formula))
            ->assertSet('error', AiFormulaModal::AI_OFF_MESSAGE);

        Livewire::test(AiNaturalLanguageQuery::class, ['spreadsheet_id' => $sheet->id])
            ->set('query', 'what is the total?')
            ->call('submitQuery')
            ->assertSet('error', AiNaturalLanguageQuery::AI_OFF_MESSAGE);

        Livewire::test(AiAnalysisPanel::class, ['spreadsheet_id' => $sheet->id, 'sheet_tab_id' => $tab->id])
            ->set('selected_range', 'A1:A3')
            ->call('runSentimentAnalysis')
            ->assertSet('error', AiAnalysisPanel::AI_OFF_MESSAGE);
    }

    public function test_the_classic_editor_hides_ai_actions_when_ai_is_off(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheet($owner);

        $this->actingAs($owner)->get(route('spreadsheets.classic', $sheet))->assertOk()
            ->assertDontSee('Write a formula with AI')->assertDontSee('Ask AI a question')
            ->assertSee('Figures, checks and rules');
    }

    public function test_the_edit_address_opens_the_grid(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheet($owner);

        $this->actingAs($owner)->get(route('spreadsheets.edit', $sheet))->assertRedirect(route('spreadsheets.show', $sheet));
    }
}
