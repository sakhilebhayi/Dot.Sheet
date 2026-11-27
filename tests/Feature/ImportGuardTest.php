<?php

namespace Tests\Feature;

use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\ImportGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Book;
use Tests\TestCase;
use ZipArchive;

/**
 * EA-1 (S-03): a file is sized before it is loaded, so a tiny file cannot make the server build a huge
 * sheet in memory. Each test would exhaust memory without the guard.
 */
class ImportGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** Two values, at the first and the very last cell a sheet can have: a few KB on disk. */
    private function farApart(): string
    {
        $book = new Book;
        $book->getActiveSheet()->setCellValue('A1', 'first');
        $book->getActiveSheet()->setCellValue('XFD1048576', 'last');
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'far').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return $path;
    }

    private function small(): string
    {
        $book = new Book;
        $book->getActiveSheet()->fromArray([['Name', 'Qty'], ['Widget', 3]]);
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'small').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return $path;
    }

    public function test_a_tiny_file_spanning_the_whole_grid_is_refused_on_create_without_loading(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());
        $before = memory_get_peak_usage();

        $this->post(route('spreadsheets.from-file'), ['file' => new UploadedFile($this->farApart(), 'far.xlsx', null, null, true)])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'first sheet spans'));

        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertSame(0, Spreadsheet::where('owner_id', $user->id)->count(), 'nothing is created for a refused file');
    }

    public function test_a_tiny_file_spanning_the_whole_grid_is_refused_when_importing_into_a_sheet(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());
        $workbook = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'team_id' => null, 'name' => 'Target']);

        $response = $this->post(route('spreadsheets.import', $workbook), [
            'file' => new UploadedFile($this->farApart(), 'far.xlsx', null, null, true),
            'sheet_tab_id' => $workbook->sheetTabs()->first()->id,
        ]);

        $this->assertStringContainsString('first sheet spans', json_encode([$response->getContent(), session('error'), session('errors')?->all()]));
    }

    public function test_an_archive_that_unpacks_too_large_is_refused_before_reading(): void
    {
        config(['sheet.import_max_unpacked_mb' => 1]);
        $path = $this->small();
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/padding.bin', str_repeat("\0", 2 * 1024 * 1024)); // compresses to a few KB
        $zip->close();

        $this->expectExceptionMessage(ImportGuard::TOO_BIG_MESSAGE);
        ImportGuard::checkArchive($path, 'xlsx');
    }

    public function test_an_ordinary_file_still_imports(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->post(route('spreadsheets.from-file'), ['file' => new UploadedFile($this->small(), 'small.xlsx', null, null, true)])
            ->assertRedirect()->assertSessionMissing('error');

        $this->assertDatabaseHas('cells', ['raw_value' => 'Widget']);
    }
}
