<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 16)->unique();
            $table->string('title', 120);
            $table->string('presenter_key_hash', 64);
            $table->unsignedBigInteger('current_slide_id')->nullable();
            $table->timestamps();
        });

        Schema::create('slides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('mime', 120);
            $table->string('storage_key', 255);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('author_name', 60);
            $table->boolean('is_presenter')->default(false);
            $table->string('body', 500);
            $table->boolean('hidden')->default(false);
            $table->boolean('pinned')->default(false);
            $table->timestamps();
            $table->index(['room_id', 'channel', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('slides');
        Schema::dropIfExists('rooms');
    }
};
