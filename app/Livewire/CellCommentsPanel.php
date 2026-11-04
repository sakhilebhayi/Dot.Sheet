<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsSheetState;
use App\Models\Comment;
use App\Models\Spreadsheet;
use App\Services\SheetComments;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CellCommentsPanel extends Component
{
    use AuthorizesRequests;
    use GuardsSheetState;

    #[Locked]
    public $spreadsheet_id;

    #[Locked]
    public $sheet_tab_id;

    public $selectedRow = 0;

    public $selectedCol = 0;

    public $threads = [];

    public $newComment = '';

    public $replyDrafts = [];

    protected $listeners = ['cell-selected' => 'onCellSelected'];

    protected function clientWritable(): array
    {
        return [
            'newComment',
            'replyDrafts',
        ];
    }

    public function mount($spreadsheet_id, $sheet_tab_id = null)
    {
        $spreadsheet = Spreadsheet::findOrFail($spreadsheet_id);
        $this->authorize('view', $spreadsheet);

        $this->spreadsheet_id = $spreadsheet_id;
        $this->sheet_tab_id = $this->ownTabId($sheet_tab_id);
        $this->loadThreads();
    }

    public function render()
    {
        $spreadsheet = Spreadsheet::find($this->spreadsheet_id);

        return view('livewire.cell-comments-panel', [
            // Courtesy only: addComment / addReply / toggleResolved / deleteComment authorize on the server.
            'canComment' => $spreadsheet !== null && (Auth::user()?->can('comment', $spreadsheet) ?? false),
        ]);
    }

    public function onCellSelected($row, $col)
    {
        $this->selectedRow = (int) $row;
        $this->selectedCol = (int) $col;
        $this->loadThreads();
    }

    public function addComment()
    {
        $this->authorize('comment', Spreadsheet::findOrFail($this->spreadsheet_id));

        $this->validate([
            'newComment' => 'required|string|min:2|max:1000',
        ]);

        $comment = Comment::create([
            'spreadsheet_id' => $this->spreadsheet_id,
            'sheet_tab_id' => $this->sheet_tab_id,
            'user_id' => Auth::id(),
            'parent_id' => null,
            'row_index' => $this->selectedRow,
            'col_index' => $this->selectedCol,
            'content' => trim($this->newComment),
            'resolved' => false,
        ]);

        $this->notifyMentions($comment);

        $this->newComment = '';
        $this->loadThreads();
    }

    public function addReply($threadId)
    {
        $this->authorize('comment', Spreadsheet::findOrFail($this->spreadsheet_id));

        $draft = trim((string) ($this->replyDrafts[$threadId] ?? ''));
        if ($draft === '') {
            return;
        }

        // A reply joins a thread of this tab, and sits on that thread's cell.
        $thread = Comment::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->whereNull('parent_id')
            ->find($threadId);

        if (! $thread) {
            return;
        }

        $comment = Comment::create([
            'spreadsheet_id' => $this->spreadsheet_id,
            'sheet_tab_id' => $this->sheet_tab_id,
            'user_id' => Auth::id(),
            'parent_id' => $thread->id,
            'row_index' => $thread->row_index,
            'col_index' => $thread->col_index,
            'content' => $draft,
            'resolved' => false,
        ]);

        $this->notifyMentions($comment);

        $this->replyDrafts[$threadId] = '';
        $this->loadThreads();
    }

    public function toggleResolved($threadId)
    {
        $this->authorize('comment', Spreadsheet::findOrFail($this->spreadsheet_id));

        $thread = Comment::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->whereNull('parent_id')
            ->where('id', $threadId)
            ->first();

        if (! $thread) {
            return;
        }

        $thread->resolved = ! $thread->resolved;
        $thread->save();

        $this->loadThreads();
    }

    public function deleteComment($commentId)
    {
        $this->authorize('comment', Spreadsheet::findOrFail($this->spreadsheet_id));

        $comment = Comment::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->where('id', $commentId)
            ->first();

        if (! $comment) {
            return;
        }

        if ($comment->user_id !== Auth::id()) {
            return;
        }

        $comment->delete();
        $this->loadThreads();
    }

    protected function loadThreads()
    {
        $threads = Comment::with(['user:id,name,email', 'replies.user:id,name,email'])
            ->where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->where('row_index', $this->selectedRow)
            ->where('col_index', $this->selectedCol)
            ->where('detached', false)
            ->whereNull('parent_id')
            ->orderByDesc('created_at')
            ->get();

        $this->threads = $threads->toArray();
    }

    protected function notifyMentions(Comment $comment): void
    {
        $spreadsheet = Spreadsheet::find($this->spreadsheet_id);
        if ($spreadsheet) {
            app(SheetComments::class)->notifyMentions($spreadsheet, $comment, Auth::user());
        }
    }
}
