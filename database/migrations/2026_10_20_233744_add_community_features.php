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
        // Half-smokes: an opt-in daily allowance of 10 to hand out to dispatches you love.
        Schema::create('half_smokes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('giver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('dispatch_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('half_smokes_on')->default(false);
            $table->boolean('is_bot')->default(false);
            $table->string('live_url', 300)->nullable();
            $table->string('live_label', 80)->nullable();
            $table->timestamp('live_until')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['half_smokes_on', 'is_bot', 'live_url', 'live_label', 'live_until']);
        });
        Schema::dropIfExists('half_smokes');
    }
};
