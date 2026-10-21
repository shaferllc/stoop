<?php

namespace App\Livewire\Pages;

use App\Models\Author;
use App\Support\Magazine;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A magazine writer's page: their imported articles and how much neighbors are talking about each.
 */
#[Layout('layouts::stoop')]
class AuthorShow extends Component
{
    #[Locked]
    public int $authorId;

    public function mount(Author $author): void
    {
        $this->authorId = $author->id;
    }

    /**
     * Follow a writer to hear when their next article is posted.
     */
    public function toggleFollow(): void
    {
        if (! Auth::check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $result = Author::findOrFail($this->authorId)->followers()->toggle(Auth::id());
        Flux::toast($result['attached'] ? 'Following. You’ll hear when their next story is up.' : 'Unfollowed.');
    }

    public function render(): View
    {
        $author = Author::with('user')->withCount('followers')->findOrFail($this->authorId);

        // ponytail: one count query per article, fine for 50; store counts if author pages get heavy
        $articles = $author->articles()->take(50)->get()->each(fn ($a) => $a->discussion = Magazine::discussion($a->url)->count());

        return view('livewire.pages.author-show', [
            'author' => $author,
            'articles' => $articles,
            'following' => Auth::check() && $author->followers()->whereKey(Auth::id())->exists(),
        ])->title($author->name)->layoutData(['description' => collect([$author->title, config('stoop.magazine_name')])->filter()->join(' · ')]);
    }
}
