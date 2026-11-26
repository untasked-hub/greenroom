<?php

namespace App\Services;

use App\Models\DefaultEmailTemplate;
use App\Models\EmailLanguage;
use App\Models\EmailTemplate;
use Illuminate\Support\Collection;

class DefaultEmailTemplateCatalog
{
    public const DEFAULT_LANGUAGE = 'en';

    /**
     * Canonical 10 supported languages (English, French, and 8 other major languages).
     *
     * @return list<array{code:string,name:string,native_name:string,direction:string,sort_order:int}>
     */
    public static function languages(): array
    {
        return [
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'sort_order' => 1],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'direction' => 'ltr', 'sort_order' => 2],
            ['code' => 'es', 'name' => 'Spanish', 'native_name' => 'Español', 'direction' => 'ltr', 'sort_order' => 3],
            ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'direction' => 'ltr', 'sort_order' => 4],
            ['code' => 'pt', 'name' => 'Portuguese', 'native_name' => 'Português', 'direction' => 'ltr', 'sort_order' => 5],
            ['code' => 'it', 'name' => 'Italian', 'native_name' => 'Italiano', 'direction' => 'ltr', 'sort_order' => 6],
            ['code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands', 'direction' => 'ltr', 'sort_order' => 7],
            ['code' => 'ja', 'name' => 'Japanese', 'native_name' => '日本語', 'direction' => 'ltr', 'sort_order' => 8],
            ['code' => 'zh', 'name' => 'Chinese', 'native_name' => '中文', 'direction' => 'ltr', 'sort_order' => 9],
            ['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'direction' => 'rtl', 'sort_order' => 10],
        ];
    }

    /**
     * @return list<string>
     */
    public static function languageCodes(): array
    {
        return array_column(self::languages(), 'code');
    }

    /**
     * Load raw translations from the JSON seed files in database/data.
     *
     * @return array<string, array<string, array{subject:string, body:string}>>
     */
    public static function loadSeedTranslations(): array
    {
        $combined = [];
        foreach (['email_translations_1.json', 'email_translations_2.json'] as $file) {
            $path = database_path('data/' . $file);
            if (! is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $combined = array_merge($combined, $decoded);
            }
        }

        return $combined;
    }

    /**
     * Seed or refresh email_languages and default_email_templates rows in Postgres/SQLite.
     */
    public static function seedDatabase(): void
    {
        $now = now();
        foreach (self::languages() as $lang) {
            EmailLanguage::query()->updateOrCreate(
                ['code' => $lang['code']],
                [
                    'name' => $lang['name'],
                    'native_name' => $lang['native_name'],
                    'direction' => $lang['direction'],
                    'sort_order' => $lang['sort_order'],
                    'updated_at' => $now,
                ]
            );
        }

        $translations = self::loadSeedTranslations();
        $meta = EmailTemplate::definitions();
        $appName = (string) config('app.name', 'Greenroom');

        foreach (self::languageCodes() as $langCode) {
            $templates = $translations[$langCode] ?? [];

            foreach ($meta as $key => $def) {
                $entry = $templates[$key] ?? null;

                $subject = is_array($entry) && isset($entry['subject'])
                    ? (string) $entry['subject']
                    : $def['default_subject'];
                $body = is_array($entry) && isset($entry['body'])
                    ? (string) $entry['body']
                    : $def['default_body_html'];

                DefaultEmailTemplate::query()->updateOrCreate(
                    [
                        'language_code' => $langCode,
                        'template_key' => $key,
                    ],
                    [
                        'group_label' => $def['group'],
                        'label' => $def['label'],
                        'audience' => $def['audience'],
                        'timing' => $def['timing'],
                        'subject' => str_replace('{{app_name}}', $appName, $subject),
                        'body_html' => str_replace('{{app_name}}', $appName, $body),
                        'attach_recording' => (bool) $def['attach_recording'],
                    ]
                );
            }
        }
    }

    /**
     * Return all available languages ordered by sort_order from the database.
     *
     * @return list<array{code:string,name:string,native_name:string,direction:string}>
     */
    public static function availableLanguages(): array
    {
        $rows = EmailLanguage::query()->orderBy('sort_order')->get();
        if ($rows->isEmpty()) {
            return array_map(fn (array $l) => [
                'code' => $l['code'],
                'name' => $l['name'],
                'native_name' => $l['native_name'],
                'direction' => $l['direction'],
            ], self::languages());
        }

        return $rows->map(fn (EmailLanguage $l) => [
            'code' => $l->code,
            'name' => $l->name,
            'native_name' => $l->native_name,
            'direction' => $l->direction,
        ])->values()->all();
    }

    /**
     * Ensure default_email_templates has been seeded at least once.
     */
    public static function ensureSeeded(): void
    {
        if (! DefaultEmailTemplate::query()->exists()) {
            self::seedDatabase();
        }
    }

    /**
     * Return DB-stored default templates for a language, falling back to English.
     *
     * @return Collection<string, DefaultEmailTemplate>
     */
    public static function defaultsForLanguage(string $languageCode): Collection
    {
        self::ensureSeeded();

        $code = in_array($languageCode, self::languageCodes(), true)
            ? $languageCode
            : self::DEFAULT_LANGUAGE;

        $rows = DefaultEmailTemplate::query()
            ->where('language_code', $code)
            ->get()
            ->keyBy('template_key');

        if ($rows->isEmpty() && $code !== self::DEFAULT_LANGUAGE) {
            $rows = DefaultEmailTemplate::query()
                ->where('language_code', self::DEFAULT_LANGUAGE)
                ->get()
                ->keyBy('template_key');
        }

        return $rows;
    }

    /**
     * Look up a single DB-stored default template by language and template_key.
     */
    public static function defaultFor(string $languageCode, string $templateKey): ?DefaultEmailTemplate
    {
        $code = in_array($languageCode, self::languageCodes(), true)
            ? $languageCode
            : self::DEFAULT_LANGUAGE;

        $row = DefaultEmailTemplate::query()
            ->where('language_code', $code)
            ->where('template_key', $templateKey)
            ->first();

        if (! $row) {
            self::ensureSeeded();
            $row = DefaultEmailTemplate::query()
                ->where('language_code', $code)
                ->where('template_key', $templateKey)
                ->first();
        }

        if (! $row && $code !== self::DEFAULT_LANGUAGE) {
            $row = DefaultEmailTemplate::query()
                ->where('language_code', self::DEFAULT_LANGUAGE)
                ->where('template_key', $templateKey)
                ->first();
        }

        return $row;
    }

    /**
     * Return every known default subject and body_html variant for a template key
     * (across all 10 languages in DB/seed JSON plus the legacy code definitions).
     *
     * @return array{subjects: list<string>, bodies: list<string>}
     */
    public static function allDefaultVariants(string $templateKey): array
    {
        $subjects = [];
        $bodies = [];
        $appName = (string) config('app.name', 'Greenroom');

        $definition = EmailTemplate::definitions()[$templateKey] ?? null;
        if (is_array($definition)) {
            $subjects[] = (string) $definition['default_subject'];
            $bodies[] = (string) $definition['default_body_html'];
        }

        $rows = DefaultEmailTemplate::query()
            ->where('template_key', $templateKey)
            ->get(['subject', 'body_html']);

        foreach ($rows as $row) {
            $subjects[] = (string) $row->subject;
            $bodies[] = (string) $row->body_html;
        }

        $translations = self::loadSeedTranslations();
        foreach ($translations as $templates) {
            $entry = $templates[$templateKey] ?? null;
            if (! is_array($entry)) {
                continue;
            }
            if (isset($entry['subject'])) {
                $rawSubject = (string) $entry['subject'];
                $subjects[] = $rawSubject;
                $subjects[] = str_replace('{{app_name}}', $appName, $rawSubject);
            }
            if (isset($entry['body'])) {
                $rawBody = (string) $entry['body'];
                $bodies[] = $rawBody;
                $bodies[] = str_replace('{{app_name}}', $appName, $rawBody);
            }
        }

        return [
            'subjects' => array_values(array_unique($subjects)),
            'bodies' => array_values(array_unique($bodies)),
        ];
    }
}
