<?php

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Recording;
use App\Models\Room;
use App\Models\WebinarRegistration;
use Illuminate\Support\Facades\Http;

const SCHED_KEY = 'email-key-789';

function webinarRoom(array $overrides = []): Room
{
    static $n = 0;
    $n++;

    return Room::create(array_merge([
        'slug' => 'sched'.$n.'x',
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

test('creating a room accepts scheduled_at and description', function () {
    $this->post('/rooms', [
        'title' => 'Planned Demo',
        'description' => 'Live walkthrough',
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
    ])->assertRedirect();

    $room = Room::where('title', 'Planned Demo')->first();
    expect($room)->not->toBeNull();
    expect($room->description)->toBe('Live walkthrough');
    expect($room->scheduled_at)->not->toBeNull();
});

test('registration page renders and live registration saves email plus sends confirmation', function () {
    $room = webinarRoom();

    $this->get('/r/'.$room->slug.'/register')->assertOk();

    $this->post('/r/'.$room->slug.'/register', [
        'name' => 'Alice Tester',
        'email' => 'ALICE@example.com',
        'registration_type' => 'live',
    ])->assertRedirect('/r/'.$room->slug.'/register');

    $registration = WebinarRegistration::where('room_id', $room->id)->first();
    expect($registration)->not->toBeNull();
    expect($registration->email)->toBe('alice@example.com');
    expect($registration->registration_type)->toBe(WebinarRegistration::TYPE_LIVE);

    $log = EmailLog::where('room_id', $room->id)
        ->where('template_key', EmailTemplate::KEY_REGISTRATION_CONFIRMATION)
        ->first();
    expect($log)->not->toBeNull();
    expect($log->recipient_email)->toBe('alice@example.com');
    expect($log->subject)->toContain('AI Product Webinar');
    expect($log->body_html)->toContain('Alice Tester');
    expect($log->status)->toBe(EmailLog::STATUS_LOGGED);
    expect($log->delivery_note)->toContain('outbox');
});

test('duplicate registration reuses the same row and resends confirmation', function () {
    $room = webinarRoom();

    foreach (['Alice', 'Alice Again'] as $name) {
        $this->post('/r/'.$room->slug.'/register', [
            'name' => $name,
            'email' => 'alice@example.com',
            'registration_type' => 'live',
        ])->assertRedirect();
    }

    expect(WebinarRegistration::where('room_id', $room->id)->count())->toBe(1);
    expect(WebinarRegistration::where('room_id', $room->id)->first()->name)->toBe('Alice Again');
    expect(EmailLog::where('template_key', EmailTemplate::KEY_REGISTRATION_CONFIRMATION)->count())->toBe(2);
});

test('replay registration logs replay confirmation with the recorded video and token-gated replay works', function () {
    Http::fake([
        'https://storage.test/objects/*' => Http::response('WEBM-VIDEO-BYTES', 200),
    ]);

    $room = webinarRoom([
        'live_started_at' => now()->subHours(2),
        'live_ended_at' => now()->subHour(),
    ]);

    Recording::create([
        'room_id' => $room->id,
        'storage_key' => 'recordings/'.$room->slug.'/test.webm',
        'mime' => 'video/webm',
        'size' => 16,
    ]);

    $this->post('/r/'.$room->slug.'/register', [
        'name' => 'Bob Replay',
        'email' => 'bob@example.com',
        'registration_type' => 'replay',
    ])->assertRedirect();

    $registration = WebinarRegistration::where('room_id', $room->id)->first();
    expect($registration->registration_type)->toBe(WebinarRegistration::TYPE_REPLAY);

    $log = EmailLog::where('room_id', $room->id)
        ->where('template_key', EmailTemplate::KEY_REPLAY_CONFIRMATION)
        ->first();
    expect($log)->not->toBeNull();
    expect($log->recording_id)->not->toBeNull();
    expect($log->video_url)->toContain('/r/'.$room->slug.'/replay/'.$registration->token.'/video');
    expect($log->body_html)->toContain('Bob Replay');

    $this->get('/r/'.$room->slug.'/replay/'.$registration->token)->assertOk();
    $this->get('/r/'.$room->slug.'/replay/'.$registration->token.'/video')
        ->assertOk()
        ->assertHeader('Content-Type', 'video/webm');
    $this->get('/r/'.$room->slug.'/replay/invalid-token')->assertNotFound();
});

test('guest joining with a registration token marks that registrant as attended', function () {
    $room = webinarRoom();
    $registration = WebinarRegistration::create([
        'room_id' => $room->id,
        'name' => 'Carol Live',
        'email' => 'carol@example.com',
        'registration_type' => WebinarRegistration::TYPE_LIVE,
        'token' => 'tok-carol-123456',
    ]);

    $this->get('/r/'.$room->slug.'?reg='.$registration->token)->assertOk();

    expect($registration->fresh()->attended_at)->not->toBeNull();
});