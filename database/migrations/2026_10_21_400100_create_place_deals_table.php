<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deals that verified owners post for their place, one running at a time.
     */
    public function up(): void
    {
        Schema::create('place_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->string('details', 500)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();

            $table->index(['place_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_deals');
    }
};
