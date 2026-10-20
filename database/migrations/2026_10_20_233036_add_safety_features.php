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
        // Warnings, timeouts and bans. subject_hash (a hash of the email) keeps a penalty
        // in force after the account is deleted, so signing up again doesn't reset it.
        Schema::create('penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_hash', 64)->index();
            $table->string('type', 10);
            $table->string('reason', 280);
            $table->foreignId('dispatch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('penalty_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('dispatch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('body', 1000);
            $table->string('status', 10)->default('open');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('moderation_reason', 280)->nullable();
            $table->boolean('hidden_by_author')->default(false);
            $table->timestamp('replies_locked_until')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->json('safety')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('replies_from', 10)->default('everyone');
            $table->timestamp('subscribed_until')->nullable();
            $table->dropColumn('muted_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['replies_from', 'subscribed_until']);
            $table->timestamp('muted_until')->nullable();
        });
        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn(['moderation_reason', 'hidden_by_author', 'replies_locked_until', 'flagged_at', 'safety']);
        });
        Schema::dropIfExists('appeals');
        Schema::dropIfExists('penalties');
    }
};
