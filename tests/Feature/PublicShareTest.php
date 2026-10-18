<?php

namespace Tests\Feature;

use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The public share link (shared/s/{token}) is opened by people who are not
 * signed in. It used to render inside the signed-in layout, whose Livewire
 * navigation menu reads the current user, so every guest got a 500.
 */
class PublicShareTest extends TestCase
{
    use RefreshDatabase;

    private function sharedSheet(array $overrides = []): Spreadsheet
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $spreadsheet = Spreadsheet::create(array_merge([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $owner->id,
            'name' => 'Budget <b>2026</b>',
            'public_enabled' => true,
            'public_token' => 'tok_'.str()->random(24),
            'public_expires_at' => null,
        ], $overrides));

        $tab = $spreadsheet->defaultSheetTab();
        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => 'Revenue', 'computed_value' => 'Revenue', 'updated_at' => now()]);
        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => 0, 'col_index' => 1, 'raw_value' => '=1+2', 'computed_value' => '3', 'updated_at' => now()]);

        return $spreadsheet;
    }

    public function test_signed_out_visitor_sees_the_shared_sheet_read_only(): void
    {
        $spreadsheet = $this->sharedSheet();

        $response = $this->get(route('spreadsheets.public', $spreadsheet->public_token));

        $response->assertOk();
        $response->assertSee('Revenue');
        $response->assertSee('Public view-only link');
        $response->assertSee('<title>Budget &lt;b&gt;2026&lt;/b&gt; - Dot.Sheet</title>', false);
        // Name is escaped, not injected as markup.
        $response->assertDontSee('<b>2026</b>', false);
        $response->assertSee('Budget &lt;b&gt;2026&lt;/b&gt;', false);
        // The displayed value, not the formula text.
        $response->assertDontSee('=1+2');
    }

    public function test_public_page_exposes_nothing_editable(): void
    {
        $spreadsheet = $this->sharedSheet();

        $html = $this->get(route('spreadsheets.public', $spreadsheet->public_token))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('wire:', $html);
        $this->assertStringNotContainsString('data-livewire', $html);
        $this->assertStringNotContainsString('contenteditable', $html);
        $this->assertStringNotContainsString('<input', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString('livewire/livewire', $html);
        // No sidebar, no account links for someone who is not signed in.
        $this->assertStringNotContainsString(route('dashboard'), $html);
        $this->assertStringNotContainsString(route('profile.show'), $html);
        // The other side of the share link is not a door into the owner's data.
        $this->assertStringNotContainsString($spreadsheet->uuid, $html);
    }

    public function test_signed_in_visitor_can_still_open_the_public_link(): void
    {
        $spreadsheet = $this->sharedSheet();
        $visitor = User::factory()->withPersonalTeam()->create();

        $this->actingAs($visitor)
            ->get(route('spreadsheets.public', $spreadsheet->public_token))
            ->assertOk()
            ->assertSee('Revenue')
            ->assertSee('Public view-only link');
    }

    public function test_public_page_shows_the_first_tab_only(): void
    {
        $spreadsheet = $this->sharedSheet();
        $second = $spreadsheet->sheetTabs()->create(['name' => 'Private notes', 'sort_order' => 1]);
        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $second->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => 'second-tab-value', 'computed_value' => 'second-tab-value', 'updated_at' => now()]);

        $this->get(route('spreadsheets.public', $spreadsheet->public_token))
            ->assertOk()
            ->assertSee('Revenue')
            ->assertDontSee('second-tab-value');
    }

    public function test_empty_sheet_renders_an_empty_state(): void
    {
        $spreadsheet = $this->sharedSheet();
        Cell::query()->delete();

        $this->get(route('spreadsheets.public', $spreadsheet->public_token))
            ->assertOk()
            ->assertSee('This spreadsheet has no data to display.');
    }

    public function test_unknown_token_is_a_plain_404_for_guests(): void
    {
        $this->get(route('spreadsheets.public', 'no-such-token'))
            ->assertNotFound();
    }

    public function test_link_that_was_switched_off_is_a_404(): void
    {
        $spreadsheet = $this->sharedSheet(['public_enabled' => false]);

        $this->get(route('spreadsheets.public', $spreadsheet->public_token))
            ->assertNotFound();
    }

    public function test_expired_link_is_a_plain_410_for_guests(): void
    {
        $spreadsheet = $this->sharedSheet(['public_expires_at' => now()->subMinute()]);

        $this->get(route('spreadsheets.public', $spreadsheet->public_token))
            ->assertStatus(410);
    }

    public function test_no_write_endpoint_is_reachable_without_signing_in(): void
    {
        $spreadsheet = $this->sharedSheet();

        $this->get(route('spreadsheets.show', $spreadsheet))->assertRedirect(route('login'));
        $this->get(route('spreadsheets.export.csv', $spreadsheet))->assertRedirect(route('login'));
        $this->post(route('spreadsheets.import', $spreadsheet))->assertRedirect(route('login'));
        $this->put(route('spreadsheets.update', $spreadsheet), ['name' => 'x'])->assertRedirect(route('login'));
        $this->delete(route('spreadsheets.destroy', $spreadsheet))->assertRedirect(route('login'));
    }

    public function test_a_guest_cannot_open_the_editor_component_even_with_a_share_link(): void
    {
        $spreadsheet = $this->sharedSheet();

        Livewire::test(ShowSpreadsheet::class, ['spreadsheet_id' => $spreadsheet->id])
            ->assertForbidden();
    }
}
