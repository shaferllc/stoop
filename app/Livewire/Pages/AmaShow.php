<?php

namespace App\Livewire\Pages;

use App\Actions\FileDispatch;
use App\Exceptions\NudgeRequired;
use App\Models\EditorThread;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts::stoop')]
class AmaShow extends Component
{
    #[Locked]
    public int $threadId;

    public string $question = '';

    /** Drafts of the editor's answers, by question id. */
    public array $answers = [];

    public bool $warned = false;

    public function mount(EditorThread $thread): void
    {
        $this->threadId = $thread->id;
    }

    protected function thread(): EditorThread
    {
        return EditorThread::with('dispatch', 'editor')->findOrFail($this->threadId);
    }

    public function updatedQuestion(): void
    {
        $this->warned = false;
    }

    public function ask(): void
    {
        abort_unless(Auth::check(), 403);
        $thread = $this->thread();

        if (! $thread->isOpen()) {
            $this->addError('question', 'Questions are closed.');

            return;
        }

        try {
            FileDispatch::handle(Auth::user(), ['body' => $this->question, 'parentId' => $thread->dispatch_id], confirmed: $this->warned);
        } catch (NudgeRequired) {
            $this->warned = true;

            return;
        }

        $this->reset('question', 'warned');
        Flux::toast('Question in. Upvotes decide what gets answered first.');
    }

    public function upvote(int $id): void
    {
        abort_unless(Auth::check(), 403);
        if (RateLimiter::attempt('ama-vote:'.Auth::id(), 60, fn () => true)) {
            $this->thread()->toggleVote(Auth::user(), $id);
        }
    }

    public function answer(int $id): void
    {
        $thread = $this->thread();
        abort_unless(Auth::id() === $thread->user_id, 403);
        abort_unless($thread->dispatch->replies()->whereKey($id)->exists(), 404);

        FileDispatch::handle(Auth::user(), ['body' => $this->answers[$id] ?? '', 'parentId' => $id], confirmed: true);
        unset($this->answers[$id]);
        Flux::toast('Answer posted.');
    }

    public function render(): View
    {
        $thread = $this->thread();

        return view('livewire.pages.ama-show', [
            'thread' => $thread,
            'questions' => $thread->questions(Auth::user()),
            'isEditor' => Auth::id() === $thread->user_id,
        ])->title('Ask the editor: '.$thread->title);
    }
}
