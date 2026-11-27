<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('saas_source')->nullable();
            $table->index(['user_id', 'saas_source', 'created_at']);
        });

        Schema::create('saas_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_customer_id');
            $table->string('stripe_subscription_id')->unique();
            $table->string('plan');
            $table->string('status');
            $table->timestamp('period_end')->nullable();
            $table->unsignedBigInteger('stripe_event_at');
            $table->timestamps();
        });

        Schema::create('saas_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_checkout_session_id')->unique();
            $table->foreignId('room_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_credits');
        Schema::dropIfExists('saas_subscriptions');

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'saas_source', 'created_at']);
            $table->dropColumn('saas_source');
        });
    }
};
