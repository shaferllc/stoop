<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Presented by" sponsors on house bots and games, and host venues for Stoop Sits meetups
     * with RSVPs and an offer checked-in attendees redeem with a code.
     */
    public function up(): void
    {
        // One sponsor per target at a time: "bot:weather", "daily", "linecup", "contest:5", "hunt:3", "challenge:7".
        Schema::create('presenters', function (Blueprint $table) {
            $table->id();
            $table->string('target', 40)->index();
            $table->string('sponsor', 60);
            $table->string('logo_path')->nullable();
            $table->string('url', 300);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('meetup_sponsors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->string('offer', 120)->nullable();
            $table->string('url', 300);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();
        });

        Schema::create('meetup_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meetup_sponsor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();

            $table->unique(['meetup_sponsor_id', 'user_id']);
        });

        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['event_rsvps', 'meetup_redemptions', 'meetup_sponsors', 'presenters'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
