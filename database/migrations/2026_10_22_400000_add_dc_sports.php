<?php

use App\Support\SearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DC sports: games imported from ESPN with live scores, favorite teams, and Happenings
     * linked to a game as watch parties.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('team_key', 30)->nullable()->index();
            $table->string('league', 10)->nullable();
            $table->unsignedSmallInteger('season')->nullable();
            $table->string('external_id', 40)->nullable();
            $table->string('status', 10)->default('pre');
            $table->string('status_detail', 60)->nullable();
            $table->unsignedSmallInteger('team_score')->nullable();
            $table->unsignedSmallInteger('opponent_score')->nullable();
            $table->string('venue', 120)->nullable();
            $table->string('stop', 4)->nullable();
            $table->timestamp('final_posted_at')->nullable();

            // The same event can be in two DC schedules (Hoyas vs. Terps), once per team.
            $table->unique(['team_key', 'external_id']);
        });

        Schema::create('team_fans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('team_key', 30)->index();
            $table->timestamps();

            $table->unique(['user_id', 'team_key']);
        });

        Schema::create('watch_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dispatch_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        SearchIndex::install();
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_parties');
        Schema::dropIfExists('team_fans');
        Schema::table('games', function (Blueprint $table) {
            $table->dropUnique(['team_key', 'external_id']);
            $table->dropIndex(['team_key']);
            $table->dropColumn(['team_key', 'league', 'season', 'external_id', 'status', 'status_detail', 'team_score', 'opponent_score', 'venue', 'stop', 'final_posted_at']);
        });
    }
};
