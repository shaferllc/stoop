<?php

namespace App\Support;

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
