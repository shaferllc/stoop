<?php

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
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question', 300);
            $table->json('choices');
            $table->unsignedTinyInteger('answer');
            $table->string('explanation', 500)->nullable();
            $table->string('source_url', 300)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // One play per person per DC calendar day.
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->json('question_ids');
            $table->json('answers');
            $table->unsignedTinyInteger('score')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('quiz_questions');
    }
};
