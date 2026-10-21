<?php

use App\Support\SearchIndex;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Adding places rebuilt the dispatches table, which drops SQLite triggers.
     */
    public function up(): void
    {
        SearchIndex::install();
    }

    public function down(): void
    {
        //
    }
};
