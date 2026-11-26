<?php

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Recording;
use App\Models\Room;
use App\Models\WebinarRegistration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Carbon;

if (! defined('SCHED_KEY')) {
    define('SCHED_KEY', 'email-key-789');
}

function scheduleRoom(array $overrides = []): Room
{
    static $n = 0;
    $n++;

    return Room::create(array_merge([
        'slug' => 'schd'.$n.'x',
        'title' => 'AI Product Webinar',
        'description' => 'Learn how to ship fast.',
        'scheduled_at' => now()->addDays(2),
        'presenter_key_hash' => hash('sha256', SCHED_KEY),
    ], $overrides));
}

beforeEach(function () {
    config([
        'greenroom.storage_url' => 'https://storage.test',
        'greenroom.storage_token' => 'token',
        'mail.default' => 'array',
    ]);
});

test('studio emails page is keyed and schedule updates room details', function () {
    $room = scheduleRoom();

    $this->get('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails')->assertOk();
    $this->get('/studio/'.$room->slug.'/wrong-key/emails')->assertNotFound();

    $this->post('/studio/'.$room->slug.'/'.SCHED_KEY.'/schedule', [
        'title' => 'Updated Webinar',
        'description' => 'Updated desc',
        'scheduled_at' => now()->addDays(5)->toIso8601String(),
    ])->assertRedirect();

    $room->refresh();
    expect($room->title)->toBe('Updated Webinar');
    expect($room->description)->toBe('Updated desc');
    expect($room->scheduled_at)->not->toBeNull();
});

test('templates update, sanitize script tags, preview with sample data, and reset', function () {
    $room = scheduleRoom();

    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/templates/'.EmailTemplate::KEY_LIVE_REMINDER, [
        'enabled' => true,
        'subject' => 'Custom 10m: {{webinar_title}}',
        'body_html' => '<p>Hello <strong>{{name}}</strong></p><script>alert(1)</script><a href="javascript:evil()" onclick="x()">now</a>',
        'attach_recording' => false,
    ])->assertOk()->assertJsonStructure(['templates']);

    $stored = $room->emailTemplates()->where('template_key', EmailTemplate::KEY_LIVE_REMINDER)->first();
    expect($stored)->not->toBeNull();
    expect($stored->subject)->toBe('Custom 10m: {{webinar_title}}');
    expect($stored->body_html)->not->toContain('<script');
    expect($stored->body_html)->not->toContain('javascript:');
    expect($stored->body_html)->not->toContain('onclick');

    $preview = $this->getJson(
        '/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/templates/'.EmailTemplate::KEY_LIVE_REMINDER.'/preview'
    )->assertOk()->json('preview');

    expect($preview['subject'])->toBe('Custom 10m: AI Product Webinar');
    expect($preview['body_html'])->toContain('Sam Presenter');
    expect($preview['video_url'])->toBeNull();

    $this->postJson(
        '/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/templates/'.EmailTemplate::KEY_LIVE_REMINDER.'/reset'
    )->assertOk();

    $defaults = EmailTemplate::definitions()[EmailTemplate::KEY_LIVE_REMINDER];
    expect($room->emailTemplates()->where('template_key', EmailTemplate::KEY_LIVE_REMINDER)->first()->subject)
        ->toBe($defaults['default_subject']);
});

test('unknown template key and invalid schedule payload are rejected', function () {
    $room = scheduleRoom();

    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/templates/nope', [
        'subject' => 'x',
        'body_html' => '<p>x</p>',
    ])->assertNotFound();

    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/schedule', [
        'scheduled_at' => 'not-a-date',
    ])->assertStatus(422);
});

test('manual dispatch endpoint sends reminders and follow-ups on demand', function () {
    $room = scheduleRoom([
        'scheduled_at' => now()->addMinutes(5),
        'live_started_at' => now()->subMinutes(60),
        'live_ended_at' => now()->subMinutes(40),
    ]);

    Recording::create([
        'room_id' => $room->id,
        'storage_key' => 'recordings/'.$room->slug.'/rec.webm',
        'mime' => 'video/webm',
        'size' => 10,
    ]);

    WebinarRegistration::create([
        'room_id' => $room->id,
        'name' => 'Manual User',
        'email' => 'manual@example.com',
        'registration_type' => WebinarRegistration::TYPE_LIVE,
        'token' => 'tok-manual-1',
    ]);

    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/dispatch', ['kind' => 'reminder'])
        ->assertOk()
        ->assertJsonPath('dispatched', 1);

    expect(EmailLog::where('template_key', EmailTemplate::KEY_LIVE_REMINDER)->count())->toBe(1);

    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/dispatch', ['kind' => 'followups'])
        ->assertOk()
        ->assertJsonPath('dispatched', 1);

    expect(EmailLog::where('template_key', EmailTemplate::KEY_NON_ATTENDEE_FOLLOWUP)->count())->toBe(1);

    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/dispatch', ['kind' => 'bogus'])
        ->assertStatus(422);
});

test('scheduled command dispatches due reminder, attendee and non-attendee follow-ups, and replay follow-up', function () {
    $reminderRoom = scheduleRoom(['scheduled_at' => now()->addMinutes(5)]);

    $endedRoom = scheduleRoom([
        'scheduled_at' => now()->subHours(2),
        'live_started_at' => now()->subMinutes(90),
        'live_ended_at' => now()->subMinutes(35),
    ]);

    Recording::create([
        'room_id' => $endedRoom->id,
        'storage_key' => 'recordings/'.$endedRoom->slug.'/rec.webm',
        'mime' => 'video/webm',
        'size' => 100,
    ]);

    WebinarRegistration::create([
        'room_id' => $reminderRoom->id,
        'name' => 'Reminder User',
        'email' => 'reminder@example.com',
        'registration_type' => WebinarRegistration::TYPE_LIVE,
        'token' => 'tok-reminder-1',
    ]);

    WebinarRegistration::create([
        'room_id' => $endedRoom->id,
        'name' => 'Attended User',
        'email' => 'attended@example.com',
        'registration_type' => WebinarRegistration::TYPE_LIVE,
        'attended_at' => now()->subMinutes(60),
        'token' => 'tok-attended-1',
    ]);

    WebinarRegistration::create([
        'room_id' => $endedRoom->id,
        'name' => 'Missed User',
        'email' => 'missed@example.com',
        'registration_type' => WebinarRegistration::TYPE_LIVE,
        'token' => 'tok-missed-1',
    ]);

    $replayReg = WebinarRegistration::create([
        'room_id' => $endedRoom->id,
        'name' => 'Replay User',
        'email' => 'replay@example.com',
        'registration_type' => WebinarRegistration::TYPE_REPLAY,
        'token' => 'tok-replay-1',
    ]);
    Carbon::setTestNow(now());
    $replayReg->forceFill(['created_at' => now()->subMinutes(40)])->save();

    Artisan::call('webinars:dispatch-emails');

    expect(EmailLog::where('template_key', EmailTemplate::KEY_LIVE_REMINDER)->count())->toBe(1);
    expect($reminderRoom->fresh()->reminder_dispatched_at)->not->toBeNull();

    expect(EmailLog::where('template_key', EmailTemplate::KEY_ATTENDEE_FOLLOWUP)
        ->where('recipient_email', 'attended@example.com')->count())->toBe(1);

    $nonAttendee = EmailLog::where('template_key', EmailTemplate::KEY_NON_ATTENDEE_FOLLOWUP)
        ->where('recipient_email', 'missed@example.com')->first();
    expect($nonAttendee)->not->toBeNull();
    expect($nonAttendee->recording_id)->not->toBeNull();
    expect($nonAttendee->video_url)->toContain('/replay/tok-missed-1/video');
    expect($endedRoom->fresh()->followup_dispatched_at)->not->toBeNull();

    expect(EmailLog::where('template_key', EmailTemplate::KEY_REPLAY_FOLLOWUP)
        ->where('recipient_email', 'replay@example.com')->count())->toBe(1);
    expect($replayReg->fresh()->replay_followup_sent_at)->not->toBeNull();

    // Running again must not duplicate anything.
    Artisan::call('webinars:dispatch-emails');
    expect(EmailLog::where('template_key', EmailTemplate::KEY_LIVE_REMINDER)->count())->toBe(1);
    expect(EmailLog::where('template_key', EmailTemplate::KEY_NON_ATTENDEE_FOLLOWUP)->count())->toBe(1);
    expect(EmailLog::where('template_key', EmailTemplate::KEY_REPLAY_FOLLOWUP)->count())->toBe(1);
});

test('disabled templates are skipped instead of being sent', function () {
    $room = scheduleRoom(['scheduled_at' => now()->addMinutes(5)]);

    WebinarRegistration::create([
        'room_id' => $room->id,
        'name' => 'Skipped User',
        'email' => 'skip@example.com',
        'registration_type' => WebinarRegistration::TYPE_LIVE,
        'token' => 'tok-skip-1',
    ]);

    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/templates/'.EmailTemplate::KEY_LIVE_REMINDER, [
        'enabled' => false,
        'subject' => 'Off',
        'body_html' => '<p>off</p>',
    ])->assertOk();

    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/dispatch', ['kind' => 'reminder'])
        ->assertOk()
        ->assertJsonPath('dispatched', 0);

    expect(EmailLog::count())->toBe(0);
});

test('10 email languages and 60 default templates are stored in the database', function () {
    \App\Services\DefaultEmailTemplateCatalog::seedDatabase();

    expect(\App\Models\EmailLanguage::count())->toBe(10);
    expect(\App\Models\DefaultEmailTemplate::count())->toBe(60);

    $codes = \App\Models\EmailLanguage::orderBy('sort_order')->pluck('code')->all();
    expect($codes)->toContain('en', 'fr', 'es', 'de', 'pt', 'it', 'nl', 'ja', 'zh', 'ar');

    $frDef = \App\Services\DefaultEmailTemplateCatalog::defaultFor('fr', EmailTemplate::KEY_REGISTRATION_CONFIRMATION);
    expect($frDef)->not->toBeNull();
    expect($frDef->subject)->not->toBeEmpty();
    expect($frDef->body_html)->toContain('{{name}}');
});

test('switching room email language updates uncustomized templates from DB defaults and keeps custom ones', function () {
    $room = scheduleRoom(['scheduled_at' => now()->addDays(2)]);

    // Customize one template
    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/templates/'.EmailTemplate::KEY_LIVE_REMINDER, [
        'enabled' => true,
        'subject' => 'Custom reminder subject',
        'body_html' => '<p>Custom body</p>',
    ])->assertOk();

    // Switch language to French without overwriting custom templates
    $res = $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/language', [
        'language' => 'fr',
        'reset_all' => false,
    ])->assertOk();

    $res->assertJsonPath('emailLanguage', 'fr');
    expect($room->fresh()->email_language)->toBe('fr');

    $liveReg = EmailTemplate::where('room_id', $room->id)
        ->where('template_key', EmailTemplate::KEY_REGISTRATION_CONFIRMATION)
        ->first();
    $frDefault = \App\Services\DefaultEmailTemplateCatalog::defaultFor('fr', EmailTemplate::KEY_REGISTRATION_CONFIRMATION);
    expect($liveReg->subject)->toBe($frDefault->subject);

    $customReminder = EmailTemplate::where('room_id', $room->id)
        ->where('template_key', EmailTemplate::KEY_LIVE_REMINDER)
        ->first();
    expect($customReminder->subject)->toBe('Custom reminder subject');

    // A normal language change keeps custom content but updates defaults.
    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/language', [
        'language' => 'es',
    ])->assertOk()->assertJsonPath('emailLanguage', 'es');

    expect($customReminder->fresh()->subject)->toBe('Custom reminder subject');

    // An explicit reset replaces customized content with the selected defaults.
    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/language', [
        'language' => 'es',
        'reset_all' => true,
    ])->assertOk()->assertJsonPath('emailLanguage', 'es');

    $esReminder = app(\App\Services\DefaultEmailTemplateCatalog::class)
        ->defaultFor('es', EmailTemplate::KEY_LIVE_REMINDER);
    expect($customReminder->fresh()->subject)->toBe($esReminder['subject']);

    // Invalid language is rejected
    $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/language', [
        'language' => 'xx',
    ])->assertUnprocessable();
});

test('template update, reset to active language, and preview endpoints return expected payloads', function () {
    $room = scheduleRoom(['scheduled_at' => now()->addDays(2), 'email_language' => 'fr']);

    $update = $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/templates/'.EmailTemplate::KEY_LIVE_REMINDER, [
        'enabled' => true,
        'attach_recording' => false,
        'subject' => 'Rappel personnalisé',
        'body_html' => '<p>Bonjour {{name}}</p>',
    ])->assertOk();

    $update->assertJsonPath('template.key', EmailTemplate::KEY_LIVE_REMINDER);
    $update->assertJsonPath('template.subject', 'Rappel personnalisé');
    $update->assertJsonStructure(['template', 'templates']);

    $preview = $this->getJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/templates/'.EmailTemplate::KEY_LIVE_REMINDER.'/preview')
        ->assertOk();
    $preview->assertJsonPath('preview.subject', 'Rappel personnalisé');

    $reset = $this->postJson('/studio/'.$room->slug.'/'.SCHED_KEY.'/emails/templates/'.EmailTemplate::KEY_LIVE_REMINDER.'/reset', [
        'language' => 'fr',
    ])->assertOk();

    $frDefault = \App\Services\DefaultEmailTemplateCatalog::defaultFor('fr', EmailTemplate::KEY_LIVE_REMINDER);
    $reset->assertJsonPath('template.key', EmailTemplate::KEY_LIVE_REMINDER);
    $reset->assertJsonPath('template.subject', $frDefault->subject);
    $reset->assertJsonStructure(['template', 'templates']);
});