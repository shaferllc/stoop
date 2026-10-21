<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Native ads: sponsored dispatches in feeds, Best of Washington party promotions, and the
     * counting behind them. Ads live apart from dispatches, so bots, points, mayors, rivalries,
     * search and moderation never see them.
     */
    public function up(): void
    {
        Schema::create('sponsored_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('advertiser', 120);
            $table->string('body', 500);
            $table->string('image_path')->nullable();
            $table->string('url', 300);
            $table->json('stops')->nullable();
            $table->json('lines')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('daily_cap')->default(3);
            $table->unsignedInteger('goal')->nullable();
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ballot_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ballot_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->date('event_on');
            $table->string('venue', 160);
            $table->string('url', 300);
            $table->string('price', 60)->nullable();
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();
        });

        // One row per ad, viewer and day. The viewer is a keyed hash of the account or session
        // id and the day, so rows can't be linked to a person or across days.
        Schema::create('ad_views', function (Blueprint $table) {
            $table->string('kind', 12);
            $table->unsignedBigInteger('ad_id');
            $table->string('viewer', 64);
            $table->date('day')->index();
            $table->unsignedSmallInteger('shows')->default(1);
            $table->unique(['kind', 'ad_id', 'viewer', 'day']);
        });

        Schema::create('ad_daily', function (Blueprint $table) {
            $table->string('kind', 12);
            $table->unsignedBigInteger('ad_id');
            $table->date('day');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unique(['kind', 'ad_id', 'day']);
        });

        // "Hide this ad" from signed-in readers, and reports (a hide with a reason).
        Schema::create('ad_hides', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12);
            $table->unsignedBigInteger('ad_id');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('reason', 30)->nullable();
            $table->timestamps();
            $table->unique(['kind', 'ad_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_hides');
        Schema::dropIfExists('ad_daily');
        Schema::dropIfExists('ad_views');
        Schema::dropIfExists('ballot_promotions');
        Schema::dropIfExists('sponsored_dispatches');
    }
};
