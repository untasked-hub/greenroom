<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->text('presenter_key_encrypted')->nullable()->after('presenter_key_hash');
            $table->string('cover_theme', 32)->nullable()->default('pistachio')->after('description');
            $table->string('cover_badge', 60)->nullable()->after('cover_theme');
            $table->string('cover_image_key', 255)->nullable()->after('cover_badge');
            $table->string('cover_image_mime', 64)->nullable()->after('cover_image_key');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'presenter_key_encrypted',
                'cover_theme',
                'cover_badge',
                'cover_image_key',
                'cover_image_mime',
            ]);
        });
    }
};
