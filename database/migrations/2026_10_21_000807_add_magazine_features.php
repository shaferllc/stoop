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
        // Magazine articles posted to Stoop from the site's feed, so nothing posts twice.
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('guid_hash', 64)->unique();
            $table->string('url', 500);
            $table->string('title', 300);
            $table->string('category', 80)->nullable();
            $table->foreignId('dispatch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // Subscription status sent by the magazine's subscription system, keyed by a hash
        // of the email so it applies even before that person signs up for Stoop.
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email_hash', 64)->unique();
            $table->timestamp('subscribed_until')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('newsletter')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('newsletter');
        });
        Schema::dropIfExists('subscribers');
        Schema::dropIfExists('articles');
    }
};
