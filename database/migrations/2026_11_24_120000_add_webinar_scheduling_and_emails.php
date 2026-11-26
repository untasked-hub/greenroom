<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->text('description')->nullable();
            $table->timestamp('reminder_dispatched_at')->nullable();
            $table->timestamp('followup_dispatched_at')->nullable();
        });

        Schema::create('webinar_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('registration_type', 16)->default('live');
            $table->string('token', 64)->unique();
            $table->timestamp('attended_at')->nullable();
            $table->timestamp('replay_followup_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['room_id', 'email', 'registration_type']);
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('template_key', 48);
            $table->boolean('enabled')->default(true);
            $table->string('subject', 200);
            $table->text('body_html');
            $table->boolean('attach_recording')->default(false);
            $table->timestamps();
            $table->unique(['room_id', 'template_key']);
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('webinar_registrations')->nullOnDelete();
            $table->string('template_key', 48);
            $table->string('recipient_name', 120);
            $table->string('recipient_email', 190);
            $table->string('subject', 200);
            $table->text('body_html');
            $table->foreignId('recording_id')->nullable()->constrained('recordings')->nullOnDelete();
            $table->string('video_url', 500)->nullable();
            $table->string('status', 24)->default('logged');
            $table->string('delivery_note', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('webinar_registrations');
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['scheduled_at', 'description', 'reminder_dispatched_at', 'followup_dispatched_at']);
        });
    }
};
