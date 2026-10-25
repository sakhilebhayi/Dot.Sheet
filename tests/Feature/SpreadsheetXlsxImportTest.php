<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SpreadsheetImportExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class SpreadsheetXlsxImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_xlsx_file_can_be_imported_into_a_spreadsheet(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, Excel::raw(new class implements FromArray
        {
            public function array(): array
            {
                return [['Name', 'Qty'], ['Widget', 3]];
            }
        }, \Maatwebsite\Excel\Excel::XLSX));

        $user = User::factory()->create();
        $spreadsheet = Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => null,
            'name' => 'Imported',
        ]);

        $file = new UploadedFile($path, 'data.xlsx', null, null, true);
        $result = app(SpreadsheetImportExportService::class)->importFile($spreadsheet, $file);

        @unlink($path);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertTrue(Cell::where('spreadsheet_id', $spreadsheet->id)->where('raw_value', 'Widget')->exists());
    }
}
