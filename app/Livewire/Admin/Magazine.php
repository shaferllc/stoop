<?php

namespace App\Livewire\Admin;

use App\Actions\FileDispatch;
use App\Models\Author;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\Magazine as MagazineFeed;
use Flux\Flux;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The magazine connection: writers from imported bylines, reader polls for articles, and quote requests.
 */
#[Layout('layouts::stoop')]
#[Title('Magazine · Admin')]
class Magazine extends Component
{
    use StaffOnly;

    public const ROLE = 'editor';

    public ?int $editing = null;

    public string $name = '';

    public string $title = '';

    public string $photoUrl = '';

    public string $username = '';

    public string $pollQuestion = '';

    public string $pollArticle = '';

    public string $pollLine = 'blue';

    public int $pollHours = 72;

    /** @var array<int, string> */
    public array $pollOptions = ['', '', '', ''];

    public ?int $pollId = null;

    public function edit(int $id): void
    {
        $author = Author::with('user')->findOrFail($id);
        $this->editing = $author->id;
        $this->name = $author->name;
        $this->title = (string) $author->title;
        $this->photoUrl = (string) $author->photo_url;
        $this->username = (string) $author->user?->username;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:80'],
            'photoUrl' => ['nullable', 'url:https', 'max:500'],
            'username' => ['nullable', 'string', 'exists:users,username'],
        ], [], ['photoUrl' => 'photo URL']);

        $author = Author::findOrFail($this->editing);
        $author->update([
            'name' => $this->name,
            'title' => $this->title ?: null,
            'photo_url' => $this->photoUrl ?: null,
            'user_id' => $this->username ? User::where('username', $this->username)->value('id') : null,
        ]);
        Audit::log('author.updated', $author, ['title' => $author->title, 'user_id' => $author->user_id]);
        $this->reset('editing', 'name', 'title', 'photoUrl', 'username');
        Flux::toast('Saved.');
    }

    /**
     * A reader poll for an article: an ordinary poll dispatch from the magazine account,
     * embeddable on the article with data-stoop-poll.
     */
    public function createPoll(): void
    {
        $this->validate(['pollArticle' => ['required', 'url:https', 'max:500']], [], ['pollArticle' => 'article URL']);

        $poll = FileDispatch::handle(MagazineFeed::account(), [
            'type' => 'poll',
            'body' => $this->pollQuestion,
            'line' => $this->pollLine,
            'options' => $this->pollOptions,
            'pollHours' => $this->pollHours,
        ], confirmed: true);
        $poll->forceFill(['details' => [...($poll->details ?? []), 'article_url' => $this->pollArticle]])->save();

        Audit::log('poll.created', $poll, ['article_url' => $this->pollArticle]);
        $this->pollId = $poll->id;
        $this->reset('pollQuestion', 'pollArticle', 'pollOptions');
        Flux::toast('Poll posted. Paste the embed code into the article.');
    }

    public function render(): View
    {
        return view('livewire.admin.magazine', [
            'authors' => Author::with('user')->withCount('followers')->orderBy('name')->get(),
            'requests' => QuoteRequest::with(['dispatch.user', 'editor'])->latest()->take(30)->get(),
        ]);
    }
}
