<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seasonal city moments: guessing contests, game-day predictions, events with
     * check-ins and meetups, and site-wide switches like Snow Day.
     */
    public function up(): void
    {
        // Guess-the-date contests, like Peak Bloom.
        Schema::create('contests', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title', 120);
            $table->string('question', 300);
            $table->string('prize', 200)->nullable();
            $table->timestamp('closes_at');
            $table->date('answer')->nullable();
            $table->unsignedTinyInteger('winners')->default(3);
            $table->timestamps();
        });

        Schema::create('contest_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('guess');
            $table->timestamps();

            $table->unique(['contest_id', 'user_id']);
        });

        // Game-day threads: a poll on who wins, settled after the game.
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('team', 40);
            $table->string('opponent', 80);
            $table->boolean('home')->default(true);
            $table->timestamp('starts_at');
            $table->foreignId('dispatch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_option_id')->nullable()->constrained('poll_options')->nullOnDelete();
            $table->foreignId('opponent_option_id')->nullable()->constrained('poll_options')->nullOnDelete();
            $table->string('winner', 10)->nullable();
            $table->timestamps();
        });

        // City events people post from (Restaurant Week, the Marine Corps Marathon, Halloween)
        // and Stoop Sits meetups with a check-in code.
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('kind', 10)->default('event');
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('rating_label', 60)->nullable();
            $table->string('place_list', 120)->nullable();
            $table->string('stop', 4)->nullable();
            $table->string('checkin_code', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('event_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->nullable()->index();
            $table->json('details')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropColumn(['event_id', 'details']);
        });
        foreach (['site_settings', 'event_checkins', 'events', 'games', 'contest_entries', 'contests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
