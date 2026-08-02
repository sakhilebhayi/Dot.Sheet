<?php

namespace App\Livewire;

use App\Models\Comment;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Notifications\MentionedInComment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class CellCommentsPanel extends Component
{
    use AuthorizesRequests;

    public $spreadsheet_id;

    public $selectedRow = 0;
    public $selectedCol = 0;

    public $threads = [];

    public $newComment = '';
    public $replyDrafts = [];

    protected $listeners = ['cell-selected' => 'onCellSelected'];

    public function mount($spreadsheet_id)
    {
        $spreadsheet = Spreadsheet::findOrFail($spreadsheet_id);
        $this->authorize('view', $spreadsheet);

        $this->spreadsheet_id = $spreadsheet_id;
        $this->loadThreads();
    }

    public function render()
    {
        return view('livewire.cell-comments-panel');
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

        $comment = Comment::create([
            'spreadsheet_id' => $this->spreadsheet_id,
            'user_id' => Auth::id(),
            'parent_id' => $threadId,
            'row_index' => $this->selectedRow,
            'col_index' => $this->selectedCol,
            'content' => $draft,
            'resolved' => false,
        ]);

        $this->notifyMentions($comment);

        $this->replyDrafts[$threadId] = '';
        $this->loadThreads();
    }

    public function toggleResolved($threadId)
    {
        $thread = Comment::where('spreadsheet_id', $this->spreadsheet_id)
            ->whereNull('parent_id')
            ->where('id', $threadId)
            ->first();

        if (!$thread) {
            return;
        }

        $thread->resolved = !$thread->resolved;
        $thread->save();

        $this->loadThreads();
    }

    public function deleteComment($commentId)
    {
        $comment = Comment::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('id', $commentId)
            ->first();

        if (!$comment) {
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
            ->where('row_index', $this->selectedRow)
            ->where('col_index', $this->selectedCol)
            ->whereNull('parent_id')
            ->orderByDesc('created_at')
            ->get();

        $this->threads = $threads->toArray();
    }

    protected function notifyMentions(Comment $comment): void
    {
        preg_match_all('/@([A-Za-z0-9_.-]{2,50})/', $comment->content, $matches);
        $tokens = array_unique($matches[1] ?? []);
        if (empty($tokens)) {
            return;
        }

        $spreadsheet = Spreadsheet::find($this->spreadsheet_id);
        if (!$spreadsheet) {
            return;
        }

        $recipientIds = collect([$spreadsheet->owner_id]);
        $recipientIds = $recipientIds->merge($spreadsheet->sharedUsers()->pluck('users.id'));
        if ($spreadsheet->team) {
            $recipientIds = $recipientIds->merge($spreadsheet->team->users()->pluck('users.id'));
        }

        $recipientIds = $recipientIds->unique()->reject(fn ($id) => (int) $id === (int) Auth::id());
        if ($recipientIds->isEmpty()) {
            return;
        }

        foreach ($tokens as $token) {
            $users = User::whereIn('id', $recipientIds)
                ->where(function ($q) use ($token) {
                    $q->where('name', 'like', $token . '%')
                      ->orWhere('email', 'like', $token . '%');
                })
                ->get();

            foreach ($users as $user) {
                $user->notify(new MentionedInComment(
                    actorName: Auth::user()?->name ?? 'Someone',
                    spreadsheetId: $this->spreadsheet_id,
                    spreadsheetName: $spreadsheet->name,
                    row: $comment->row_index + 1,
                    col: $comment->col_index + 1,
                    excerpt: mb_strimwidth($comment->content, 0, 140, '...'),
                ));
            }
        }
    }
}
