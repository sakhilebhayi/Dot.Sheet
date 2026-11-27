<?php

namespace Tests\Feature;

use App\Models\Spreadsheet;
use App\Models\User;
use App\Notifications\MentionedInComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Default']);
    }

    public function test_a_new_sheet_opens_in_the_new_editor(): void
    {
        $this->actingAs($this->owner)->post('/spreadsheets', ['name' => 'Fresh'])
            ->assertRedirect(route('spreadsheets.show', Spreadsheet::where('name', 'Fresh')->firstOrFail()));
    }

    public function test_old_links_to_the_preview_still_arrive(): void
    {
        $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}/grid")->assertRedirect("/spreadsheets/{$this->sheet->id}");
    }

    public function test_the_dashboard_opens_sheets_in_the_new_editor_and_stops_calling_it_a_preview(): void
    {
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()
            ->assertSee(route('spreadsheets.show', $this->sheet), false)
            ->assertDontSee('Preview the new editor');
    }

    public function test_a_mention_email_opens_where_comments_are(): void
    {
        $mail = (new MentionedInComment('Sam', $this->sheet->id, $this->sheet->name, 2, 3, 'Look at this', 42))->toMail($this->owner);
        $this->assertStringEndsWith("/spreadsheets/{$this->sheet->id}?tab=42&cell=C2", $mail->actionUrl);

        // One already waiting in a queue when this shipped has no sheet to name.
        $older = (new MentionedInComment('Sam', $this->sheet->id, $this->sheet->name, 2, 3, 'Look at this'))->toMail($this->owner);
        $this->assertStringEndsWith("/spreadsheets/{$this->sheet->id}?cell=C2", $older->actionUrl);
    }

    public function test_neither_the_editor_nor_the_old_classic_address_opens_for_a_stranger(): void
    {
        foreach (['', '/classic'] as $editor) {
            $this->actingAs(User::factory()->withPersonalTeam()->create())->get("/spreadsheets/{$this->sheet->id}{$editor}")->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        foreach (['', '/classic', '/grid'] as $editor) {
            $this->get("/spreadsheets/{$this->sheet->id}{$editor}")->assertRedirect(route('login'));
        }
    }

    public function test_the_retired_classic_address_opens_the_grid(): void
    {
        $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}/classic")->assertRedirect(route('spreadsheets.show', $this->sheet));
    }
}
