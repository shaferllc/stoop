<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business accounts (a paid Stripe plan for place owners), self-serve promoted posts, the
     * Stripe webhook log that makes webhooks idempotent, and daily place numbers for owners.
     */
    public function up(): void
    {
        // One per owner; it covers every place they manage.
        Schema::create('business_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('stripe_customer_id', 80)->nullable()->index();
            $table->string('stripe_subscription_id', 80)->nullable()->unique();
            $table->string('status', 24)->default('incomplete');
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->unsignedSmallInteger('failed_payments')->default(0);
            $table->timestamp('payment_failed_at')->nullable();
            $table->timestamps();
        });

        // A paid (or free monthly) boost of a deal, happening or dispatch. Once staff approve it,
        // it runs as a sponsored_dispatches row marked promoted.
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12);
            $table->unsignedBigInteger('target_id');
            $table->string('name', 120);
            $table->json('stops')->nullable();
            $table->json('lines')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('tier', 12);
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('impressions');
            $table->boolean('free')->default(false);
            $table->string('status', 12)->default('unpaid')->index();
            $table->string('stripe_session_id', 120)->nullable()->unique();
            $table->string('payment_intent', 80)->nullable();
            $table->foreignId('sponsored_dispatch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason', 200)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sponsored_dispatches', function (Blueprint $table) {
            $table->boolean('promoted')->default(false);
        });

        // Every Stripe event handled, so a retried delivery is a no-op.
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id', 80)->primary();
            $table->string('type', 80);
            $table->timestamp('created_at')->nullable();
        });

        // Owner analytics: unique page views, deal views and deal clicks per place and day.
        Schema::create('place_daily', function (Blueprint $table) {
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('deal_views')->default(0);
            $table->unsignedInteger('deal_clicks')->default(0);
            $table->unique(['place_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_daily');
        Schema::dropIfExists('stripe_events');
        Schema::table('sponsored_dispatches', fn (Blueprint $table) => $table->dropColumn('promoted'));
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('business_accounts');
    }
};
