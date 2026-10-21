<?php

namespace App\Livewire\Admin;

use App\Models\Issue;
use App\Support\ArticleUpdates;
use App\Support\Audit;
use App\Support\Issues as IssueDrop;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Issue drops, and corrections or updates to articles already posted.
 */
#[Layout('layouts::stoop')]
#[Title('Issues · Admin')]
class Issues extends Component
{
    use StaffOnly;

    public const ROLE = 'editor';

    public string $name = '';

    public string $coverUrl = '';

    public string $urls = '';

    /** @var array<int, string> Update notes by article id. */
    public array $notes = [];

    public function addArticle(int $id): void
    {
        $url = DB::table('articles')->where('id', $id)->value('url');
        if ($url && ! str_contains($this->urls, $url)) {
            $this->urls = trim($this->urls."\n".$url);
        }
    }

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'coverUrl' => ['nullable', 'url:http,https', 'max:500'],
            'urls' => ['required', 'string', 'max:20000'],
        ], [], ['coverUrl' => 'cover image URL', 'urls' => 'story URLs']);

        $stories = IssueDrop::storiesFrom($this->urls);
        if (! $stories) {
            $this->addError('urls', 'Add at least one story URL starting with https://.');

            return;
        }

        $slug = Str::slug($this->name) ?: 'issue';
        $issue = Issue::create([
            'slug' => Issue::where('slug', $slug)->exists() ? $slug.'-'.Str::lower(Str::random(4)) : $slug,
            'name' => trim($this->name),
            'cover_url' => $this->coverUrl ?: null,
            'stories' => $stories,
        ]);
        Audit::log('issue.created', $issue);
        $this->reset('name', 'coverUrl', 'urls');
        Flux::toast('Saved. Check it, then publish.');
    }

    public function publish(int $id): void
    {
        $issue = Issue::findOrFail($id);

        try {
            $thread = IssueDrop::publish($issue);
        } catch (Throwable $e) {
            Flux::toast('Couldn’t post the issue: '.$e->getMessage(), variant: 'danger');

            return;
        }

        Audit::log('issue.published', $issue, ['dispatch_id' => $thread?->id]);
        Flux::toast('Posted as @'.config('stoop.magazine_account').'.');
    }

    public function delete(int $id): void
    {
        $issue = Issue::whereNull('published_at')->findOrFail($id);
        Audit::log('issue.deleted', $issue, ['name' => $issue->name]);
        $issue->delete();
    }

    /**
     * An editor marks an article corrected or updated: @magazine posts the note in its thread.
     */
    public function postUpdate(int $articleId): void
    {
        $note = trim((string) ($this->notes[$articleId] ?? ''));
        if (mb_strlen($note) < 5 || mb_strlen($note) > 400) {
            $this->addError('notes.'.$articleId, 'Write a note of 5 to 400 characters.');

            return;
        }

        $article = DB::table('articles')->find($articleId);
        $notified = $article ? ArticleUpdates::post($article, $note, Str::random(32), $this->staff()) : null;
        if ($notified === null) {
            Flux::toast('That article has no thread on Stoop to update.', variant: 'danger');

            return;
        }

        Audit::log('article.updated', null, ['article_id' => $articleId, 'note' => $note, 'notified' => $notified]);
        unset($this->notes[$articleId]);
        Flux::toast("Posted. $notified ".str('person')->plural($notified).' notified.');
    }

    public function render(): View
    {
        return view('livewire.admin.issues', [
            'issues' => Issue::latest()->take(20)->get(),
            'articles' => DB::table('articles')->whereNotNull('dispatch_id')->latest('created_at')->take(15)->get(),
            'updates' => DB::table('article_updates')->join('articles', 'articles.id', '=', 'article_updates.article_id')
                ->latest('article_updates.created_at')->take(10)->get(['article_updates.*', 'articles.title']),
        ]);
    }
}
