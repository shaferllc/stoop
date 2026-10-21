<?php

use App\Support\SearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The article's lead image from the magazine's feed, for article cards.
        Schema::table('articles', function (Blueprint $table) {
            $table->string('image_url', 500)->nullable();
        });

        // "Ask the editor": the editor's dispatch is the thread; readers' questions are its
        // replies, and the editor's replies to a question are the answers.
        Schema::create('editor_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
        });

        Schema::create('question_votes', function (Blueprint $table) {
            $table->foreignId('dispatch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['dispatch_id', 'user_id']);
        });

        // Best of Washington reader ballots.
        Schema::create('ballots', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->timestamps();
        });

        Schema::create('ballot_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ballot_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('position')->default(0);
        });

        Schema::create('ballot_nominees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ballot_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->unique(['ballot_category_id', 'place_id']);
        });

        Schema::create('ballot_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ballot_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ballot_nominee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['ballot_category_id', 'user_id']);
        });

        // ANC meeting notes pasted by staff, the summary (Claude's draft, edited by staff)
        // and when it was posted.
        Schema::create('anc_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('anc', 4);
            $table->text('notes');
            $table->text('summary');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        SearchIndex::install();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anc_summaries');
        Schema::dropIfExists('ballot_votes');
        Schema::dropIfExists('ballot_nominees');
        Schema::dropIfExists('ballot_categories');
        Schema::dropIfExists('ballots');
        Schema::dropIfExists('question_votes');
        Schema::dropIfExists('editor_threads');
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
