<?php

use App\Models\DefaultEmailTemplate;
use App\Models\EmailTemplate;
use App\Models\Room;
use App\Services\DefaultEmailTemplateCatalog;
use App\Services\WebinarEmailService;

test('switching email campaign language persists language and reloads legacy English default templates into French', function () {
    $presenterKey = 'lang-legacy-key';
    $room = Room::create([
        'slug' => 'lang-legacy-room',
        'title' => 'Editorial Webinar',
        'description' => 'Testing language switching',
        'presenter_key_hash' => hash('sha256', $presenterKey),
        'email_language' => 'en',
    ]);

    // Seed room with legacy EmailTemplate::definitions() English defaults
    // (exactly what pre-multilingual rooms had in production).
    foreach (EmailTemplate::definitions() as $key => $def) {
        EmailTemplate::create([
            'room_id' => $room->id,
            'template_key' => $key,
            'enabled' => true,
            'subject' => $def['default_subject'],
            'body_html' => $def['default_body_html'],
            'attach_recording' => $def['attach_recording'],
        ]);
    }

    $response = $this->postJson("/studio/{$room->slug}/{$presenterKey}/emails/language", [
        'language' => 'fr',
    ]);

    $response->assertOk()
        ->assertJsonPath('emailLanguage', 'fr');

    $room->refresh();
    expect($room->email_language)->toBe('fr');

    $templates = collect($response->json('templates'))->keyBy('key');
    $frenchReplayDefault = DefaultEmailTemplateCatalog::defaultFor('fr', EmailTemplate::KEY_REPLAY_CONFIRMATION);

    expect($frenchReplayDefault)->not->toBeNull();
    expect($templates[EmailTemplate::KEY_REPLAY_CONFIRMATION]['subject'])
        ->toBe($frenchReplayDefault->subject)
        ->not->toBe('Your replay for {{webinar_title}} is ready');
    expect($templates[EmailTemplate::KEY_REPLAY_CONFIRMATION]['body_html'])
        ->toBe($frenchReplayDefault->body_html)
        ->toContain('Bonjour');

    // Reloading the Inertia emails page returns persisted French language and French templates
    $page = $this->get("/studio/{$room->slug}/{$presenterKey}/emails");
    $page->assertOk();
    $props = $page->original->getData()['page']['props'];
    expect($props['emailLanguage'])->toBe('fr');
    expect($props['room']['email_language'])->toBe('fr');
    $reloadedReplay = collect($props['templates'])->firstWhere('key', EmailTemplate::KEY_REPLAY_CONFIRMATION);
    expect($reloadedReplay['subject'])->toBe($frenchReplayDefault->subject);
});

test('switching language across multiple languages reloads TipTap-normalized defaults while preserving custom templates unless reset_all is set', function () {
    $presenterKey = 'lang-tiptap-key';
    $room = Room::create([
        'slug' => 'lang-tiptap-room',
        'title' => 'Multilingual Launch',
        'presenter_key_hash' => hash('sha256', $presenterKey),
        'email_language' => 'en',
    ]);

    app(WebinarEmailService::class)->ensureTemplates($room);

    // Simulate TipTap adding target/rel attributes on links without changing content
    $replay = $room->emailTemplates()->where('template_key', EmailTemplate::KEY_REPLAY_CONFIRMATION)->firstOrFail();
    $replay->update([
        'body_html' => str_replace(
            '<a href="{{replay_url}}">',
            '<a href="{{replay_url}}" target="_blank" rel="noopener noreferrer">',
            $replay->body_html
        ),
    ]);

    // Customize one template explicitly
    $registrationConfirm = $room->emailTemplates()->where('template_key', EmailTemplate::KEY_REGISTRATION_CONFIRMATION)->firstOrFail();
    $registrationConfirm->update([
        'subject' => 'My custom VIP welcome subject',
        'body_html' => '<p>Custom VIP body content</p>',
    ]);

    // Switch EN -> FR
    $frRes = $this->postJson("/studio/{$room->slug}/{$presenterKey}/emails/language", [
        'language' => 'fr',
    ])->assertOk();

    $frTemplates = collect($frRes->json('templates'))->keyBy('key');
    $frReplay = DefaultEmailTemplateCatalog::defaultFor('fr', EmailTemplate::KEY_REPLAY_CONFIRMATION);

    expect($frTemplates[EmailTemplate::KEY_REPLAY_CONFIRMATION]['body_html'])->toBe($frReplay->body_html);
    expect($frTemplates[EmailTemplate::KEY_REGISTRATION_CONFIRMATION]['subject'])->toBe('My custom VIP welcome subject');

    // Switch FR -> ES with reset_all = true resets even custom templates
    $esRes = $this->postJson("/studio/{$room->slug}/{$presenterKey}/emails/language", [
        'language' => 'es',
        'reset_all' => true,
    ])->assertOk();

    $esTemplates = collect($esRes->json('templates'))->keyBy('key');
    $esRegistrationConfirm = DefaultEmailTemplateCatalog::defaultFor('es', EmailTemplate::KEY_REGISTRATION_CONFIRMATION);

    expect($room->fresh()->email_language)->toBe('es');
    expect($esTemplates[EmailTemplate::KEY_REGISTRATION_CONFIRMATION]['subject'])->toBe($esRegistrationConfirm->subject);
});

test('catalog auto-seeds default_email_templates if empty and schedule settings can persist email_language', function () {
    DefaultEmailTemplate::query()->delete();
    expect(DefaultEmailTemplate::query()->count())->toBe(0);

    $frDefault = DefaultEmailTemplateCatalog::defaultFor('fr', EmailTemplate::KEY_REGISTRATION_CONFIRMATION);
    expect($frDefault)->not->toBeNull();
    expect($frDefault->language_code)->toBe('fr');
    expect(DefaultEmailTemplate::query()->count())->toBeGreaterThanOrEqual(60);

    $presenterKey = 'schedule-lang-key';
    $room = Room::create([
        'slug' => 'schedule-lang-room',
        'title' => 'Schedule Lang Room',
        'presenter_key_hash' => hash('sha256', $presenterKey),
        'email_language' => 'en',
    ]);

    $this->post("/studio/{$room->slug}/{$presenterKey}/schedule", [
        'title' => 'Schedule Lang Room',
        'description' => 'Updated description',
        'email_language' => 'de',
    ])->assertRedirect();

    expect($room->fresh()->email_language)->toBe('de');
    $deConfirm = DefaultEmailTemplateCatalog::defaultFor('de', EmailTemplate::KEY_REGISTRATION_CONFIRMATION);
    $stored = $room->emailTemplates()->where('template_key', EmailTemplate::KEY_REGISTRATION_CONFIRMATION)->firstOrFail();
    expect($stored->subject)->toBe($deConfirm->subject);
});
