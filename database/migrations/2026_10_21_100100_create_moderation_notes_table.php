<?php

use App\Support\SearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Claude's moderation suggestions and appeal notes, kept so they aren't asked for twice.
     */
    public function up(): void
    {
        Schema::create('moderation_notes', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->foreignId('dispatch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('appeal_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->timestamps();
            $table->index(['kind', 'dispatch_id']);
            $table->index(['kind', 'appeal_id']);
        });

        SearchIndex::install();
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_notes');
    }
};
