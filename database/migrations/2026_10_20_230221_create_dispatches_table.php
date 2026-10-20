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
        Schema::create('dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('dispatches')->cascadeOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('dispatches')->nullOnDelete();
            $table->string('type', 20)->default('dispatch');
            $table->string('line', 10);
            $table->string('stop', 4)->nullable();
            $table->text('body');
            $table->string('happening_at', 60)->nullable();
            $table->string('status', 10)->default('approved')->index();
            $table->timestamps();

            $table->index(['parent_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispatches');
    }
};
