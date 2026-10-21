<?php

namespace App\Support;

use App\Models\Dispatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The SQLite full-text index behind search. SQLite drops triggers when Laravel rebuilds
 * the dispatches table (adding a foreign key does that), so any migration that changes
 * that table should call install() again at the end.
 */
class SearchIndex
{
    public static function install(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        self::uninstall();
        DB::statement("CREATE VIRTUAL TABLE dispatches_fts USING fts5(body, content='dispatches', content_rowid='id', tokenize='porter unicode61')");
        DB::statement("INSERT INTO dispatches_fts(dispatches_fts) VALUES ('rebuild')");
        DB::statement('CREATE TRIGGER dispatches_fts_ai AFTER INSERT ON dispatches BEGIN INSERT INTO dispatches_fts(rowid, body) VALUES (new.id, new.body); END');
        DB::statement("CREATE TRIGGER dispatches_fts_ad AFTER DELETE ON dispatches BEGIN INSERT INTO dispatches_fts(dispatches_fts, rowid, body) VALUES ('delete', old.id, old.body); END");
        DB::statement("CREATE TRIGGER dispatches_fts_au AFTER UPDATE OF body ON dispatches BEGIN INSERT INTO dispatches_fts(dispatches_fts, rowid, body) VALUES ('delete', old.id, old.body); INSERT INTO dispatches_fts(rowid, body) VALUES (new.id, new.body); END");
    }

    /**
     * Dispatches and replies matching every word, with stemming ("restaurants" finds
     * "restaurant"), best match first. Shared by the search page and the app API.
     *
     * @param  Builder<Dispatch>  $query
     * @return Collection<int, Dispatch>
     */
    public static function search(Builder $query, string $q): Collection
    {
        if (DB::getDriverName() !== 'sqlite') {
            return $query->where('body', 'like', '%'.$q.'%')->latest()->take(50)->get();
        }

        // Quote each word so punctuation can't change the FTS query; prefix-match the last one.
        $terms = collect(preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $q)))->filter()->take(8);
        if ($terms->isEmpty()) {
            return collect();
        }
        $match = $terms->map(fn ($t, $i) => '"'.$t.'"'.($i === $terms->keys()->last() ? '*' : ''))->join(' ');
        $ids = DB::select('SELECT rowid FROM dispatches_fts WHERE dispatches_fts MATCH ? ORDER BY rank LIMIT 200', [$match]);
        $order = array_flip(array_column($ids, 'rowid'));

        // ponytail: keyword search with stemming; add embeddings for true search-by-meaning if people ask for it
        return $query->whereIn('dispatches.id', array_keys($order))->get()->sortBy(fn ($d) => $order[$d->id])->take(50)->values();
    }

    public static function uninstall(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach (['ai', 'ad', 'au'] as $t) {
            DB::statement("DROP TRIGGER IF EXISTS dispatches_fts_$t");
        }
        DB::statement('DROP TABLE IF EXISTS dispatches_fts');
    }
}
