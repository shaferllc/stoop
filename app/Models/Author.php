<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A magazine writer, made automatically from the bylines of imported articles. Staff add
 * the title ("Food critic") and photo, and can connect the writer's own Stoop account.
 */
#[Fillable(['name', 'slug', 'title', 'photo_url', 'user_id'])]
class Author extends Model
{
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'author_follows')->withTimestamps();
    }

    /**
     * The writer for a byline name, created on first sight. The slug never changes, so
     * staff can tidy up the display name without breaking the match.
     */
    public static function forName(string $name): ?self
    {
        $name = Str::limit(trim((string) preg_replace('/\s+/', ' ', $name)), 120, '');
        $slug = Str::slug($name);

        return $slug === '' ? null : self::firstOrCreate(['slug' => $slug], ['name' => $name]);
    }

    /**
     * Imported articles under this byline, newest first.
     */
    public function articles(): Builder
    {
        return DB::table('articles')->join('article_author', 'article_author.article_id', '=', 'articles.id')
            ->where('article_author.author_id', $this->id)->select('articles.*')
            ->orderByDesc('articles.published_at')->orderByDesc('articles.id');
    }

    /**
     * Writers credited on the article posted as this dispatch, in byline order.
     *
     * @return Collection<int, self>
     */
    public static function forDispatch(int $dispatchId): Collection
    {
        return self::query()->join('article_author', 'article_author.author_id', '=', 'authors.id')
            ->join('articles', 'articles.id', '=', 'article_author.article_id')
            ->where('articles.dispatch_id', $dispatchId)->orderBy('article_author.position')->select('authors.*')->get();
    }

    public function url(): string
    {
        return route('authors.show', $this);
    }
}
