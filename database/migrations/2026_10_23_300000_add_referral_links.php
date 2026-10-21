<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reservation links on places, and outbound clicks on reservation and ticket links by
     * place (or happening), provider and DC day. 0 stands for "none" so the unique key works.
     */
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->string('reservation_url', 500)->nullable();
        });

        Schema::create('referral_clicks', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->unsignedBigInteger('place_id')->default(0);
            $table->unsignedBigInteger('dispatch_id')->default(0);
            $table->string('provider', 30);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();

            $table->unique(['day', 'place_id', 'dispatch_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_clicks');
        Schema::table('places', fn (Blueprint $table) => $table->dropColumn('reservation_url'));
    }
};
