<?php

use App\Services\DefaultEmailTemplateCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_languages')) {
            Schema::create('email_languages', function (Blueprint $table) {
                $table->string('code', 10)->primary();
                $table->string('name', 64);
                $table->string('native_name', 64);
                $table->string('direction', 3)->default('ltr');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('default_email_templates')) {
            Schema::create('default_email_templates', function (Blueprint $table) {
                $table->id();
                $table->string('language_code', 10)->index();
                $table->string('template_key', 64);
                $table->string('group_label', 64)->default('');
                $table->string('label', 120)->default('');
                $table->string('audience', 120)->default('');
                $table->string('timing', 120)->default('');
                $table->string('subject', 240);
                $table->text('body_html');
                $table->boolean('attach_recording')->default(false);
                $table->timestamps();

                $table->unique(['language_code', 'template_key']);
                $table->foreign('language_code')
                    ->references('code')
                    ->on('email_languages')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('rooms', 'email_language')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->string('email_language', 10)->default('en')->after('description');
            });
        }

        DefaultEmailTemplateCatalog::seedDatabase();
    }

    public function down(): void
    {
        if (Schema::hasColumn('rooms', 'email_language')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->dropColumn('email_language');
            });
        }

        Schema::dropIfExists('default_email_templates');
        Schema::dropIfExists('email_languages');
    }
};
