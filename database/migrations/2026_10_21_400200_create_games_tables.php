<?php

use App\Support\SearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metro Bingo's hand-marked squares and seasonal scavenger hunts.
     */
    public function up(): void
    {
        // Only squares marked by hand, each with the post that proves it. Squares Stoop can
        // detect are worked out from activity and never stored.
        Schema::create('bingo_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week');
            $table->string('square', 40);
            $table->foreignId('dispatch_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'week', 'square']);
        });

        Schema::create('hunts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hunt_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hunt_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('clue', 300);
            $table->string('stop', 4)->nullable();
            $table->foreignId('place_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code_word', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('hunt_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hunt_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dispatch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['hunt_step_id', 'user_id']);
        });

        SearchIndex::install();
    }

    public function down(): void
    {
        foreach (['hunt_completions', 'hunt_steps', 'hunts', 'bingo_marks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
