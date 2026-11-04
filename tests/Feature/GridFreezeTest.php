<?php

namespace Tests\Feature;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SheetStructureService;
use App\Services\SpreadsheetImportExportService;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class GridFreezeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Frozen']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function freeze(int $rows, int $cols, ?int $version = null): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/freeze", [
            'version' => $version ?? StructureVersion::current($this->sheet->id),
            'rows' => $rows,
            'cols' => $cols,
        ]);
    }

    public function test_a_freeze_is_saved_and_the_answer_puts_back_what_was_there(): void
    {
        $this->freeze(2, 3)->assertOk()->assertJsonPath('undo', ['rows' => 0, 'cols' => 1]);

        $this->assertSame(2, $this->tab->fresh()->layout['frozen_rows']);
        $this->assertSame(3, $this->tab->fresh()->layout['frozen_cols']);

        $this->freeze(0, 0)->assertOk()->assertJsonPath('undo', ['rows' => 2, 'cols' => 3]);
    }

    public function test_the_sheet_sends_its_freeze_to_the_browser(): void
    {
        $this->freeze(1, 2);

        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/sheet")->assertOk()
            ->assertJsonPath('layout.frozen_rows', 1)->assertJsonPath('layout.frozen_cols', 2);
    }

    public function test_a_freeze_has_limits(): void
    {
        $this->freeze(51, 0)->assertUnprocessable();
        $this->freeze(0, 21)->assertUnprocessable();
        $this->freeze(-1, 0)->assertUnprocessable();
    }

    public function test_a_freeze_against_an_old_structure_changes_nothing(): void
    {
        $version = StructureVersion::current($this->sheet->id);
        StructureVersion::bump($this->sheet->id);

        $this->freeze(2, 2, $version)->assertStatus(409)->assertJsonPath('stale', true);

        $this->assertNull($this->tab->fresh()->layout);
    }

    public function test_only_someone_who_can_edit_may_freeze(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        $this->actingAs($viewer);
        $this->freeze(1, 1)->assertForbidden();

        $this->assertNull($this->tab->fresh()->layout);
    }

    public function test_a_row_or_column_added_inside_the_frozen_part_grows_it(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->freeze(3, 2);
        $service = app(SheetStructureService::class);

        $service->insertRow($this->sheet->id, $this->tab->id, 1);
        $this->assertSame(4, $this->tab->fresh()->layout['frozen_rows']);

        $service->insertRow($this->sheet->id, $this->tab->id, 10);
        $this->assertSame(4, $this->tab->fresh()->layout['frozen_rows']);

        $service->deleteRow($this->sheet->id, $this->tab->id, 0);
        $this->assertSame(3, $this->tab->fresh()->layout['frozen_rows']);

        $service->insertColumn($this->sheet->id, $this->tab->id, 0);
        $this->assertSame(3, $this->tab->fresh()->layout['frozen_cols']);
    }

    public function test_excel_export_carries_the_freeze(): void
    {
        $this->freeze(2, 1);

        $path = app(SpreadsheetImportExportService::class)->exportExcel($this->sheet, $this->tab->fresh());

        $workbook = IOFactory::load($path);
        $this->assertSame('B3', $workbook->getSheet(0)->getFreezePane());
        $workbook->disconnectWorksheets();
        unlink($path);
    }

    public function test_a_sheet_nobody_froze_exports_with_no_pane(): void
    {
        $path = app(SpreadsheetImportExportService::class)->exportExcel($this->sheet, $this->tab->fresh());

        $workbook = IOFactory::load($path);
        $this->assertNull($workbook->getSheet(0)->getFreezePane());
        $workbook->disconnectWorksheets();
        unlink($path);
    }
}
