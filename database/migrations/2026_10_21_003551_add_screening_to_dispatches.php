<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the automatic checks found, so moderators can see why a post was held.
     */
    public function up(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            $table->json('screening')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropColumn('screening');
        });
    }
};
