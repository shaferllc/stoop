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
        // Restaurants, bars and shops, usually imported from the magazine's lists.
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('category', 40)->nullable();
            $table->string('neighborhood', 60)->nullable();
            $table->string('stop', 4)->nullable()->index();
            $table->string('address', 160)->nullable();
            $table->string('website', 300)->nullable();
            $table->text('blurb')->nullable();
            $table->string('list_name', 120)->nullable();
            $table->unsignedSmallInteger('list_rank')->nullable();
            $table->string('article_url', 300)->nullable();
            // The business owner, once a claim is approved. They can reply as the business.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('place_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 80);
            $table->string('evidence', 500);
            $table->string('status', 10)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A business sponsoring a station or line for a time. Always labeled.
        Schema::create('sponsorships', function (Blueprint $table) {
            $table->id();
            $table->string('sponsor', 120);
            $table->foreignId('place_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stop', 4)->nullable();
            $table->string('line', 10)->nullable();
            $table->string('headline', 120);
            $table->string('body', 280)->nullable();
            $table->string('url', 300)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->foreignId('place_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sponsor', 120)->nullable();
            $table->boolean('as_business')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('place_id');
            $table->dropColumn(['sponsor', 'as_business']);
        });
        Schema::dropIfExists('sponsorships');
        Schema::dropIfExists('place_claims');
        Schema::dropIfExists('places');
    }
};
