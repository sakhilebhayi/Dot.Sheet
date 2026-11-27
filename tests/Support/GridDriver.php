<?php

namespace Tests\Support;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\StructureVersion;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Drives the grid editor's own HTTP endpoints the way the page does, for tests written against the retired classic
 * editor (EA-6). It answers the few calls those tests made: type into a cell, switch, add and rename sheets, and
 * insert or delete a line at the selection. Every write sends the structure version, as the page does.
 */
final class GridDriver
{
    private int $tab;

    /** @var array<string, mixed> */
    private array $set = [];

    private ?TestResponse $last = null;

    public function __construct(private TestCase $test, private User $user, private Spreadsheet $sheet)
    {
        $this->tab = (int) $sheet->defaultSheetTab()->id;
        $this->last = $this->as()->getJson($this->tabUrl('sheet'));
    }

    public function set(string $key, mixed $value): self
    {
        $this->set[$key] = $value;

        return $this;
    }

    public function call(string $action, mixed ...$args): self
    {
        $version = StructureVersion::current($this->sheet->id);
        $this->last = match ($action) {
            'saveCell' => $this->as()->postJson($this->tabUrl('cells'), ['version' => $version, 'edits' => [[...$this->editing(), 'value' => (string) $args[0]]]]),
            'switchTab' => $this->switchTo((int) $args[0]),
            'createTab' => $this->created($this->as()->postJson("/spreadsheets/{$this->sheet->id}/tabs", ['name' => (string) $args[0]])),
            'renameTab' => $this->as()->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$args[0]}/rename", ['version' => $version, 'name' => (string) $args[1]]),
            'insertRowSelected' => $this->structure('insert', 'row', (int) $this->set['selectedRow']),
            'deleteRowSelected' => $this->structure('delete', 'row', (int) $this->set['selectedRow']),
            'insertColumnSelected' => $this->structure('insert', 'col', (int) $this->set['selectedCol']),
            'deleteColumnSelected' => $this->structure('delete', 'col', (int) $this->set['selectedCol']),
            default => throw new \InvalidArgumentException("The grid has no {$action}."),
        };

        return $this;
    }

    public function assertOk(): self
    {
        $this->last?->assertSuccessful();

        return $this;
    }

    public function response(): ?TestResponse
    {
        return $this->last;
    }

    private function as(): TestCase
    {
        return $this->test->actingAs($this->user);
    }

    private function tabUrl(string $path): string
    {
        return "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab}/{$path}";
    }

    /** @return array{row: int, col: int} */
    private function editing(): array
    {
        [$row, $col] = array_map('intval', explode('_', (string) $this->set['editingCell']));

        return ['row' => $row, 'col' => $col];
    }

    private function structure(string $action, string $axis, int $at): TestResponse
    {
        return $this->as()->postJson($this->tabUrl('structure'), ['version' => StructureVersion::current($this->sheet->id), 'action' => $action, 'axis' => $axis, 'at' => $at, 'count' => 1]);
    }

    private function switchTo(int $tab): TestResponse
    {
        $this->tab = $tab;

        return $this->as()->getJson($this->tabUrl('sheet'));
    }

    private function created(TestResponse $response): TestResponse
    {
        $id = SheetTab::where('spreadsheet_id', $this->sheet->id)->orderByDesc('id')->value('id');
        if ($id !== null) {
            $this->tab = (int) $id;
        }

        return $response;
    }
}
