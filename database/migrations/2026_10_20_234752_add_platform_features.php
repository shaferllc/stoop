<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('translation_language', 10)->nullable();
        });

        // Deleting an account leaves authorless tombstones where other people replied.
        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('dispatches', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('content_hash', 64);
            $table->string('language', 10);
            $table->text('text');
            $table->string('source_language', 40)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->unique(['content_hash', 'language']);
        });

        // Full-text search with stemming, so "restaurants" finds "restaurant".
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE VIRTUAL TABLE dispatches_fts USING fts5(body, content='dispatches', content_rowid='id', tokenize='porter unicode61')");
            DB::statement('INSERT INTO dispatches_fts(rowid, body) SELECT id, body FROM dispatches');
            DB::statement('CREATE TRIGGER dispatches_fts_ai AFTER INSERT ON dispatches BEGIN INSERT INTO dispatches_fts(rowid, body) VALUES (new.id, new.body); END');
            DB::statement("CREATE TRIGGER dispatches_fts_ad AFTER DELETE ON dispatches BEGIN INSERT INTO dispatches_fts(dispatches_fts, rowid, body) VALUES ('delete', old.id, old.body); END");
            DB::statement("CREATE TRIGGER dispatches_fts_au AFTER UPDATE OF body ON dispatches BEGIN INSERT INTO dispatches_fts(dispatches_fts, rowid, body) VALUES ('delete', old.id, old.body); INSERT INTO dispatches_fts(rowid, body) VALUES (new.id, new.body); END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['ai', 'ad', 'au'] as $t) {
                DB::statement("DROP TRIGGER IF EXISTS dispatches_fts_$t");
            }
            DB::statement('DROP TABLE IF EXISTS dispatches_fts');
        }
        Schema::dropIfExists('translations');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('translation_language');
        });
    }
};
