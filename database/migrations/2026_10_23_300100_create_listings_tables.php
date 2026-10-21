<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classifieds: jobs, housing, roommates and for-sale listings. Paid categories wait as
     * "unpaid" until Stripe Checkout confirms payment. Contact is through Stoop messages only.
     */
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('title', 100);
            $table->text('body');
            $table->string('stop', 4)->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->unsignedInteger('pay_min')->nullable();
            $table->unsignedInteger('pay_max')->nullable();
            $table->string('pay_period', 10)->nullable();
            // unpaid, active, held, removed, filled
            $table->string('status', 10)->default('unpaid');
            $table->json('screening')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('checkout_session', 255)->nullable();
            $table->unsignedInteger('paid_cents')->default(0);
            $table->timestamps();

            $table->index(['status', 'category', 'expires_at']);
            $table->index('stop');
        });

        Schema::create('listing_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 30);
            $table->string('note', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['listing_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_reports');
        Schema::dropIfExists('listings');
    }
};
