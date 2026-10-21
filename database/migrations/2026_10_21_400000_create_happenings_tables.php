<?php

use App\Support\SearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Happenings on the calendar: a real start time next to the free-text "when", tickets
     * (an outside link, no payments), staff features, and personal calendar feeds.
     */
    public function up(): void
    {
        Schema::create('happenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at')->nullable()->index();
            $table->unsignedInteger('price_cents')->nullable();
            $table->string('ticket_url', 500)->nullable();
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('ticket_clicks')->default(0);
            $table->timestamps();
        });

        // The secret link calendar apps subscribe to. Kept off the users table so it never
        // rides along when a user is serialized.
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamps();
        });

        SearchIndex::install();
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
        Schema::dropIfExists('happenings');
    }
};
