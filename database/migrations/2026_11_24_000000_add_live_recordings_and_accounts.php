<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamp('live_started_at')->nullable();
            $table->timestamp('live_ended_at')->nullable();
            $table->string('stage_source', 16)->default('slide');
            $table->string('speaker_layout', 16)->default('sidebar');
            $table->unsignedInteger('current_slide_page')->default(1);
        });

        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('storage_key', 255);
            $table->string('mime', 100)->default('video/webm');
            $table->unsignedBigInteger('size');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('sender', 64);
            $table->string('recipient', 64);
            $table->string('kind', 24);
            $table->text('payload');
            $table->timestamps();
            $table->index(['room_id', 'recipient', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
        Schema::dropIfExists('recordings');
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'live_started_at', 'live_ended_at', 'stage_source', 'speaker_layout', 'current_slide_page']);
        });
    }
};
