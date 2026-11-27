<?php

namespace Tests\Feature;

use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_the_edit_address_opens_the_grid(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheet($owner);

        $this->actingAs($owner)->get(route('spreadsheets.edit', $sheet))->assertRedirect(route('spreadsheets.show', $sheet));
    }
}
