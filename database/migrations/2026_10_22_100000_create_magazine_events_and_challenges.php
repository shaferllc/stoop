<?php

use App\Support\SearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Things-to-do listings imported from the magazine as Happenings, and list challenges
     * ("Eat at 10 of the 100 Very Best") with the people who finished them.
     */
    public function up(): void
    {
        // One row per event posted, so an event is never posted twice. The key is the event's
        // name, slugged: the same event shows up in next week's roundup too.
        Schema::create('magazine_events', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->index();
            $table->string('title', 200);
            $table->string('url', 500);
            $table->foreignId('dispatch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at');
            $table->string('source', 10)->default('feed');
            $table->timestamps();
        });

        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 140)->unique();
            $table->string('title', 160);
            $table->string('list_name', 120);
            $table->unsignedSmallInteger('goal');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('prize', 500)->nullable();
            $table->string('badge_name', 60);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Finishing is recorded once; badges, XP and the staff prize list read from here.
        Schema::create('challenge_finishers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('finished_at');
            $table->timestamps();
            $table->unique(['challenge_id', 'user_id']);
        });

        SearchIndex::install();
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_finishers');
        Schema::dropIfExists('challenges');
        Schema::dropIfExists('magazine_events');
    }
};
