<?php

namespace Tests\Feature;

use App\Livewire\CellCommentsPanel;
use App\Models\Comment;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\User;
use App\Notifications\MentionedInComment;
use App\Services\CellWriter;
use App\Services\SheetComments;
use App\Services\SheetVersions;
use App\Services\StructureVersion;
use App\Services\WorkbookSheets;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class GridCommentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sheet.structure_ops_enabled' => true]);
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Noted']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function url(string $path = 'comments', ?SheetTab $tab = null, ?Spreadsheet $sheet = null): string
    {
        return '/spreadsheets/'.($sheet ?? $this->sheet)->id.'/tabs/'.($tab ?? $this->tab)->id."/{$path}";
    }

    private function comment(int $row, int $col, string $text = 'Check this', ?SheetTab $tab = null): Comment
    {
        return app(SheetComments::class)->add($this->sheet->id, ($tab ?? $this->tab)->id, $this->owner->id, $row, $col, $text);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ask(string $what, array $body, ?SheetTab $tab = null): TestResponse
    {
        return $this->postJson($this->url($what, $tab), ['version' => StructureVersion::current($this->sheet->id), ...$body]);
    }

    /**
     * @param  list<string>  $values  one row each, in column A
     */
    private function column(array $values): void
    {
        $edits = [];
        foreach ($values as $row => $value) {
            $edits[] = ['row' => $row, 'col' => 0, 'value' => $value];
        }
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, $edits, $this->owner->id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function replay(array $groups): array
    {
        $back = [];
        foreach ($groups as $group) {
            $tab = SheetTab::findOrFail($group['tab']);
            $answer = $this->ask('cells', ['edits' => $group['edits'], ...(isset($group['comments']) ? ['comments' => $group['comments']] : [])], $tab)->assertOk()->json();
            $back = [...$back, ...$answer['undo']];
        }

        return $back;
    }

    public function test_a_comment_is_added_and_read_back_with_its_replies(): void
    {
        $answer = $this->postJson($this->url(), ['row' => 2, 'col' => 1, 'text' => "Is this right?\nIt looks low."])->assertOk()->json();

        $this->assertSame([[2, 1, 1]], $answer['marks']);
        $this->assertSame("Is this right?\nIt looks low.", $answer['threads'][0]['text']);
        $this->assertTrue($answer['threads'][0]['mine']);

        $this->postJson($this->url("comments/{$answer['threads'][0]['id']}/reply"), ['text' => 'Looks fine'])->assertOk();

        $read = $this->getJson($this->url())->assertOk()->json();
        $this->assertSame('Looks fine', $read['threads'][0]['replies'][0]['text']);
        $this->assertSame(0, $read['more']);
        $this->assertSame([[2, 1, 1]], $read['marks'], 'a reply is not another mark');
    }

    public function test_threads_come_open_first_in_reading_order(): void
    {
        $this->comment(5, 0, 'lower');
        $this->comment(1, 3, 'right');
        $this->comment(1, 2, 'left');
        $this->comment(0, 0, 'done')->update(['resolved' => true]);

        $this->assertSame(['left', 'right', 'lower', 'done'], array_column($this->getJson($this->url())->json('threads'), 'text'));
    }

    public function test_a_resolved_thread_leaves_no_mark_and_can_be_reopened(): void
    {
        $thread = $this->comment(0, 0);

        $this->postJson($this->url("comments/{$thread->id}/resolve"), ['resolved' => true])->assertOk()->assertJsonPath('marks', []);
        $this->assertTrue($thread->fresh()->resolved);

        $this->postJson($this->url("comments/{$thread->id}/resolve"), ['resolved' => false])->assertOk()->assertJsonPath('marks', [[0, 0, 1]]);
    }

    public function test_the_sheet_says_where_its_marks_are(): void
    {
        $this->comment(4, 2);
        $this->comment(4, 2, 'Another');

        $this->getJson($this->url('sheet'))->assertOk()->assertJsonPath('marks', [[4, 2, 2]]);
    }

    public function test_only_the_author_deletes_and_a_thread_takes_its_replies(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($other->id, ['permission' => 'comment']);
        $thread = $this->comment(0, 0);
        $reply = app(SheetComments::class)->reply($thread, $other->id, 'A reply');

        $this->actingAs($other);
        $this->deleteJson($this->url("comments/{$thread->id}"))->assertForbidden();
        $this->assertNotNull($thread->fresh());

        $this->actingAs($this->owner);
        $this->deleteJson($this->url("comments/{$reply->id}"))->assertForbidden();
        $this->deleteJson($this->url("comments/{$thread->id}"))->assertOk()->assertJsonPath('threads', []);
        $this->assertNull($reply->fresh(), 'its replies go with it');
    }

    public function test_someone_who_may_comment_but_not_edit_can_comment_and_nothing_more(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($other->id, ['permission' => 'comment']);

        $this->actingAs($other);
        $this->postJson($this->url(), ['row' => 0, 'col' => 0, 'text' => 'A thought'])->assertOk();
        $this->ask('cells', ['edits' => [['row' => 0, 'col' => 0, 'value' => 'x']]])->assertForbidden();
    }

    public function test_a_reader_reads_comments_and_cannot_write_them(): void
    {
        $thread = $this->comment(0, 0);
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->actingAs($reader);
        $this->getJson($this->url())->assertOk()->assertJsonPath('threads.0.mine', false);
        $this->postJson($this->url(), ['row' => 0, 'col' => 0, 'text' => 'No'])->assertForbidden();
        $this->postJson($this->url("comments/{$thread->id}/reply"), ['text' => 'No'])->assertForbidden();
        $this->postJson($this->url("comments/{$thread->id}/resolve"), ['resolved' => true])->assertForbidden();
        $this->deleteJson($this->url("comments/{$thread->id}"))->assertForbidden();
    }

    public function test_a_stranger_sees_nothing(): void
    {
        $this->comment(0, 0);

        $this->actingAs(User::factory()->withPersonalTeam()->create());
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_a_comment_is_reached_only_through_its_own_sheet(): void
    {
        $second = app(WorkbookSheets::class)->add($this->sheet, 'Second');
        $elsewhere = $this->comment(0, 0, 'On the second', $second);
        $reply = app(SheetComments::class)->reply($this->comment(0, 0), $this->owner->id, 'A reply');

        $this->postJson($this->url("comments/{$elsewhere->id}/reply"), ['text' => 'Here'])->assertNotFound();
        $this->postJson($this->url("comments/{$elsewhere->id}/resolve"), ['resolved' => true])->assertNotFound();
        $this->deleteJson($this->url("comments/{$elsewhere->id}"))->assertNotFound();
        $this->postJson($this->url("comments/{$reply->id}/reply"), ['text' => 'On a reply'])->assertNotFound();

        $otherBook = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Other']);
        $this->getJson($this->url('comments', $this->tab, $otherBook))->assertNotFound();
    }

    public function test_a_comment_needs_words_and_a_place_on_the_sheet(): void
    {
        $this->postJson($this->url(), ['row' => 0, 'col' => 0, 'text' => '   '])->assertUnprocessable();
        $this->postJson($this->url(), ['row' => 0, 'col' => 0, 'text' => str_repeat('a', 1001)])->assertUnprocessable();
        $this->postJson($this->url(), ['row' => 0, 'col' => 100, 'text' => 'Off the side'])->assertUnprocessable();
    }

    public function test_a_mention_tells_that_person_and_its_link_opens_the_new_editor_at_the_cell(): void
    {
        Notification::fake();
        $friend = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena']);
        $outsider = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Other']);
        $this->sheet->sharedUsers()->attach($friend->id, ['permission' => 'comment']);

        $this->postJson($this->url(), ['row' => 2, 'col' => 1, 'text' => 'Please look @Thandi'])->assertOk();

        Notification::assertSentTo($friend, MentionedInComment::class, function (MentionedInComment $sent) use ($friend) {
            $mail = $sent->toMail($friend);
            $this->assertSame(route('spreadsheets.show', $this->sheet)."?tab={$this->tab->id}&cell=B3", $mail->actionUrl);

            return true;
        });
        Notification::assertNotSentTo($outsider, MentionedInComment::class);
    }

    public function test_a_reply_mentions_too_but_never_the_writer(): void
    {
        Notification::fake();
        $this->owner->update(['name' => 'Sipho Writer']);
        $thread = $this->comment(0, 0);

        $this->postJson($this->url("comments/{$thread->id}/reply"), ['text' => 'Note to @Sipho'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_message_that_fails_leaves_the_comment_standing(): void
    {
        $friend = User::factory()->withPersonalTeam()->create(['name' => 'Thandi']);
        $this->sheet->sharedUsers()->attach($friend->id, ['permission' => 'comment']);
        $this->mock(Dispatcher::class)->shouldReceive('send')->andThrow(new \RuntimeException('Mail is down'));

        $this->postJson($this->url(), ['row' => 0, 'col' => 0, 'text' => 'Over to @Thandi'])->assertOk()->assertJsonCount(1, 'threads');
    }

    public function test_a_comment_follows_its_row_and_column_when_lines_go_in(): void
    {
        $below = $this->comment(3, 1);
        $above = $this->comment(1, 1);
        app(SheetComments::class)->reply($below, $this->owner->id, 'A reply');

        $this->ask('structure', ['action' => 'insert', 'axis' => 'row', 'at' => 2, 'count' => 2])->assertOk();
        $this->assertSame([5, 1], [$below->fresh()->row_index, $below->fresh()->col_index]);
        $this->assertSame(1, $above->fresh()->row_index);
        $this->assertSame([5, 5], Comment::where('parent_id', $below->id)->pluck('row_index')->prepend($below->fresh()->row_index)->all());

        $this->ask('structure', ['action' => 'insert', 'axis' => 'col', 'at' => 1, 'count' => 1])->assertOk();
        $this->assertSame(2, $below->fresh()->col_index);
        $this->getJson($this->url('sheet'))->assertJsonPath('marks', [[1, 2, 1], [5, 2, 1]]);
    }

    public function test_a_line_going_out_moves_those_after_it_and_keeps_the_words_of_those_on_it(): void
    {
        $this->column(['a', 'b', 'c', 'd']);
        $on = $this->comment(1, 0, 'On the row that goes');
        $reply = app(SheetComments::class)->reply($on, $this->owner->id, 'And its reply');
        $after = $this->comment(3, 0);
        $before = $this->comment(0, 0);

        $this->ask('structure', ['action' => 'delete', 'axis' => 'row', 'at' => 1, 'count' => 1])->assertOk();

        $this->assertTrue($on->fresh()->detached);
        $this->assertTrue($reply->fresh()->detached);
        $this->assertSame(2, $after->fresh()->row_index);
        $this->assertSame(0, $before->fresh()->row_index);
        $this->assertFalse($after->fresh()->detached);

        $answer = $this->getJson($this->url())->json();
        $this->assertSame([[0, 0, 1], [2, 0, 1]], $answer['marks'], 'a comment with no cell leaves no mark');
        $this->assertSame('On the row that goes', $answer['threads'][2]['text']);
        $this->assertTrue($answer['threads'][2]['detached']);
    }

    public function test_deleting_several_lines_counts_each_one(): void
    {
        $this->column(['a', 'b', 'c', 'd', 'e']);
        $first = $this->comment(1, 0);
        $second = $this->comment(2, 0);
        $after = $this->comment(4, 0);

        $this->ask('structure', ['action' => 'delete', 'axis' => 'row', 'at' => 1, 'count' => 2])->assertOk();

        $this->assertTrue($first->fresh()->detached);
        $this->assertTrue($second->fresh()->detached);
        $this->assertSame(1, $first->fresh()->row_index, 'a detached comment stays out of later shifts');
        $this->assertSame(1, $second->fresh()->row_index);
        $this->assertSame(2, $after->fresh()->row_index);
    }

    public function test_an_insert_that_pushes_a_comment_off_the_sheet_keeps_its_words(): void
    {
        $edge = $this->comment(0, 99);

        $this->ask('structure', ['action' => 'insert', 'axis' => 'col', 'at' => 5, 'count' => 1])->assertOk();

        $this->assertTrue($edge->fresh()->detached);
        $this->assertSame([], app(SheetComments::class)->marks($this->tab->id));
    }

    public function test_undoing_a_delete_brings_comments_back_to_their_cells(): void
    {
        $this->column(['a', 'b', 'c']);
        $on = $this->comment(1, 0);
        $after = $this->comment(2, 0);

        $copy = $this->ask('structure', ['action' => 'delete', 'axis' => 'row', 'at' => 1, 'count' => 1])->assertOk()->json('undo.restore');
        $this->assertNotNull($copy);
        $this->assertSame(1, $after->fresh()->row_index);

        $this->ask('restore', ['copy' => $copy])->assertOk();

        $this->assertFalse($on->fresh()->detached);
        $this->assertSame(1, $on->fresh()->row_index);
        $this->assertSame(2, $after->fresh()->row_index);
    }

    public function test_putting_back_a_copy_leaves_newer_comments_and_keeps_deleted_ones_deleted(): void
    {
        $this->column(['a']);
        $kept = $this->comment(0, 0);
        $gone = $this->comment(1, 0);
        $copy = app(SheetVersions::class)->snapshot($this->sheet->id, $this->tab->id, $this->owner->id, SheetVersions::MANUAL, 'Before');

        $gone->delete();
        $newer = $this->comment(6, 2);
        app(SheetComments::class)->shift($this->tab->id, 'row_index', 0, 1);

        app(SheetVersions::class)->restore($copy, $this->owner->id);

        $this->assertSame(0, $kept->fresh()->row_index);
        $this->assertSame(7, $newer->fresh()->row_index);
        $this->assertNull($gone->fresh());
    }

    public function test_a_copy_from_before_comments_were_kept_leaves_them_alone(): void
    {
        $this->column(['a']);
        $comment = $this->comment(3, 0);
        $copy = app(SheetVersions::class)->snapshot($this->sheet->id, $this->tab->id, $this->owner->id, SheetVersions::MANUAL, 'Old');
        SpreadsheetVersion::whereKey($copy->id)->update(['comment_places' => null]);
        $comment->update(['row_index' => 5]);

        app(SheetVersions::class)->restore($copy->fresh(), $this->owner->id);

        $this->assertSame(5, $comment->fresh()->row_index);
    }

    public function test_a_sort_takes_comments_with_their_rows_and_undo_brings_them_back(): void
    {
        $this->column(['Name', 'Cara', 'Abe', 'Bea']);
        $onCara = $this->comment(1, 0, 'Cara');
        $onAbe = $this->comment(2, 1, 'Beside Abe');
        $reply = app(SheetComments::class)->reply($onAbe, $this->owner->id, 'Reply on Abe');
        $outside = $this->comment(2, 5, 'Outside the block');

        $answer = $this->ask('sort', ['r1' => 1, 'r2' => 3, 'c1' => 0, 'c2' => 1, 'key' => 0, 'descending' => false])->assertOk()->json();

        $this->assertSame(3, $onCara->fresh()->row_index, 'Cara sorts last');
        $this->assertSame(1, $onAbe->fresh()->row_index, 'Abe sorts first');
        $this->assertSame(1, $reply->fresh()->row_index);
        $this->assertSame(2, $outside->fresh()->row_index, 'a column that did not move keeps its comment');
        $this->assertSame([[1, 1, 1], [2, 5, 1], [3, 0, 1]], $answer['marks']);

        $redo = $this->replay($answer['undo']);
        $this->assertSame(1, $onCara->fresh()->row_index);
        $this->assertSame(2, $onAbe->fresh()->row_index);
        $this->assertSame(2, $reply->fresh()->row_index);

        $this->replay($redo);
        $this->assertSame(3, $onCara->fresh()->row_index);
        $this->assertSame(1, $onAbe->fresh()->row_index);
    }

    public function test_a_sort_that_is_refused_moves_no_comment(): void
    {
        $this->column(['2', '1']);
        $comment = $this->comment(0, 0);

        $this->postJson($this->url('sort'), ['version' => 999, 'r1' => 0, 'r2' => 1, 'c1' => 0, 'c2' => 0, 'key' => 0, 'descending' => false])->assertConflict();

        $this->assertSame(0, $comment->fresh()->row_index);
    }

    public function test_cut_cells_take_their_comments_and_undo_brings_them_back(): void
    {
        $this->column(['one', 'two']);
        $moved = $this->comment(1, 0);
        $stays = $this->comment(0, 0);

        $answer = $this->ask('paste', ['from_tab' => $this->tab->id, 'r1' => 1, 'r2' => 1, 'c1' => 0, 'c2' => 0, 'row' => 4, 'col' => 2, 'rows' => 1, 'cols' => 1, 'cut' => true])->assertOk()->json();

        $this->assertSame([4, 2], [$moved->fresh()->row_index, $moved->fresh()->col_index]);
        $this->assertSame(0, $stays->fresh()->row_index);
        $this->assertSame([[0, 0, 1], [4, 2, 1]], $answer['marks']);

        $this->replay($answer['undo']);
        $this->assertSame([1, 0], [$moved->fresh()->row_index, $moved->fresh()->col_index]);
    }

    public function test_cut_cells_take_their_comments_to_another_sheet_and_back(): void
    {
        $this->column(['one']);
        $second = app(WorkbookSheets::class)->add($this->sheet, 'Second');
        $moved = $this->comment(0, 0);

        $answer = $this->ask('paste', ['from_tab' => $this->tab->id, 'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0, 'row' => 2, 'col' => 2, 'rows' => 1, 'cols' => 1, 'cut' => true], $second)->assertOk()->json();

        $this->assertSame([$second->id, 2, 2], [$moved->fresh()->sheet_tab_id, $moved->fresh()->row_index, $moved->fresh()->col_index]);
        $this->assertSame([[2, 2, 1]], $answer['marks']);

        $this->replay($answer['undo']);
        $this->assertSame([$this->tab->id, 0, 0], [$moved->fresh()->sheet_tab_id, $moved->fresh()->row_index, $moved->fresh()->col_index]);
    }

    public function test_copying_leaves_comments_where_they_are(): void
    {
        $this->column(['one']);
        $this->comment(0, 0);

        $answer = $this->ask('paste', ['from_tab' => $this->tab->id, 'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0, 'row' => 4, 'col' => 2, 'rows' => 1, 'cols' => 1])->assertOk()->json();

        $this->assertArrayNotHasKey('marks', $answer);
        $this->assertSame(0, Comment::firstOrFail()->row_index);
    }

    public function test_undo_cannot_place_a_comment_on_another_workbook(): void
    {
        $this->column(['one']);
        $comment = $this->comment(0, 0);
        $otherBook = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Other']);
        $otherTab = $otherBook->sheetTabs()->firstOrFail();

        $this->ask('cells', ['edits' => [['row' => 9, 'col' => 9, 'value' => 'x']], 'comments' => [['id' => $comment->id, 'tab' => $otherTab->id, 'row' => 1, 'col' => 1]]])->assertOk();

        $this->assertSame($this->tab->id, $comment->fresh()->sheet_tab_id);
    }

    public function test_undo_cannot_reach_a_comment_in_another_workbook(): void
    {
        $otherBook = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Other']);
        $otherTab = $otherBook->sheetTabs()->firstOrFail();
        $theirs = app(SheetComments::class)->add($otherBook->id, $otherTab->id, $this->owner->id, 3, 3, 'Theirs');

        $this->ask('cells', ['edits' => [['row' => 9, 'col' => 9, 'value' => 'x']], 'comments' => [['id' => $theirs->id, 'tab' => $this->tab->id, 'row' => 0, 'col' => 0]]])->assertOk();

        $this->assertSame([$otherTab->id, 3], [$theirs->fresh()->sheet_tab_id, $theirs->fresh()->row_index]);
    }

    public function test_the_classic_panel_does_not_show_a_comment_with_no_cell(): void
    {
        $detached = $this->comment(1, 0, 'Its row went');
        $detached->update(['detached' => true]);
        $placed = $this->comment(1, 0, 'On the row now here');

        Livewire::test(CellCommentsPanel::class, ['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id])
            ->call('onCellSelected', 1, 0)
            ->assertSee($placed->content)
            ->assertDontSee($detached->content);
    }

    public function test_a_mention_from_the_classic_panel_goes_through_the_same_path(): void
    {
        Notification::fake();
        $friend = User::factory()->withPersonalTeam()->create(['name' => 'Thandi']);
        $this->sheet->sharedUsers()->attach($friend->id, ['permission' => 'comment']);

        Livewire::test(CellCommentsPanel::class, ['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id])
            ->set('newComment', 'For @Thandi')
            ->call('addComment');

        Notification::assertSentTo($friend, MentionedInComment::class, fn (MentionedInComment $sent) => $sent->sheetTabId === $this->tab->id);
    }

    public function test_deleting_a_sheet_says_what_comments_go_with_it(): void
    {
        $second = app(WorkbookSheets::class)->add($this->sheet, 'Second');
        $thread = $this->comment(0, 0, 'One', $second);
        app(SheetComments::class)->reply($thread, $this->owner->id, 'A reply is not counted again');
        $this->comment(1, 0, 'Two', $second);

        $this->getJson($this->url('removal', $second))->assertOk()->assertJsonPath('comments', 2);
    }

    public function test_the_page_says_who_may_comment(): void
    {
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->get("/spreadsheets/{$this->sheet->id}")->assertOk()->assertViewHas('boot', fn (array $boot) => $boot['canComment'] === true);
        $this->actingAs($reader);
        $this->get("/spreadsheets/{$this->sheet->id}")->assertOk()->assertViewHas('boot', fn (array $boot) => $boot['canComment'] === false);
    }
}
