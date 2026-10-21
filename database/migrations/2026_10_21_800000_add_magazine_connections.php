<?php

use App\Support\SearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bylines from imported articles. Staff can add a title and photo, and connect a Stoop account.
        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('title', 80)->nullable();
            $table->string('photo_url', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('article_author', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->primary(['article_id', 'author_id']);
        });

        Schema::create('author_follows', function (Blueprint $table) {
            $table->foreignId('author_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['author_id', 'user_id']);
        });

        // An editor asking to quote a dispatch in a story. Expires after 14 days unanswered.
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        // Who agreed to what, and when. No foreign keys, so the record outlives the post
        // and the request; the text is kept only as a hash.
        Schema::create('quote_consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quote_request_id')->index();
            $table->unsignedBigInteger('dispatch_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('username', 60)->nullable();
            $table->string('action', 12);
            $table->string('body_hash', 64)->nullable();
            $table->timestamp('created_at');
        });

        SearchIndex::install();
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_consents');
        Schema::dropIfExists('quote_requests');
        Schema::dropIfExists('author_follows');
        Schema::dropIfExists('article_author');
        Schema::dropIfExists('authors');
        SearchIndex::install();
    }
};
