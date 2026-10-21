<?php

use App\Support\SearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reply an Ask DC author picked as the Best Answer.
     */
    public function up(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            $table->foreignId('best_answer_id')->nullable()->constrained('dispatches')->nullOnDelete();
        });

        // Adding a foreign key rebuilds the table in SQLite, which drops the search triggers.
        SearchIndex::install();
    }

    public function down(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('best_answer_id');
        });
        SearchIndex::install();
    }
};
