<?php

use App\Models\Recording;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

const LIVE_KEY = 'live-key-456';

function liveRoom(?User $owner = null): Room
{
    return Room::create([
        'slug' => 'live1234',
        'title' => 'Product Launch',
        'presenter_key_hash' => hash('sha256', LIVE_KEY),
        'user_id' => $owner?->id,
    ]);
}

beforeEach(function () {
    config(['greenroom.storage_url' => 'https://storage.test', 'greenroom.storage_token' => 'token']);
});

test('presenter can go live, update layout, and end live', function () {
    $room = liveRoom();

    $this->postJson('/studio/live1234/'.LIVE_KEY.'/go-live')
        ->assertOk()
        ->assertJsonPath('live.is_live', true);

    expect($room->fresh()->isLive())->toBeTrue();

    $this->postJson('/studio/live1234/'.LIVE_KEY.'/layout', [
        'stage_source' => 'screen',
        'speaker_layout' => 'overlay',
        'current_slide_page' => 3,
    ])->assertOk()
        ->assertJsonPath('live.stage_source', 'screen')
        ->assertJsonPath('live.speaker_layout', 'overlay')
        ->assertJsonPath('live.current_slide_page', 3);

    $this->postJson('/studio/live1234/'.LIVE_KEY.'/end-live')
        ->assertOk()
        ->assertJsonPath('live.is_live', false);

    expect($room->fresh()->isLive())->toBeFalse();
});

test('go live and layout reject wrong presenter key', function () {
    liveRoom();

    $this->postJson('/studio/live1234/bad-key/go-live')->assertNotFound();
    $this->postJson('/studio/live1234/bad-key/end-live')->assertNotFound();
    $this->postJson('/studio/live1234/bad-key/layout', ['stage_source' => 'screen'])->assertNotFound();
});

test('presenter uploads a recording and owner can stream it', function () {
    Http::fake([
        'https://storage.test/objects/*' => Http::sequence()
            ->push('ok', 200)
            ->push('WEBM-BYTES', 200),
    ]);

    $owner = User::create([
        'name' => 'Host',
        'email' => 'host@example.com',
        'password' => 'secret123',
    ]);
    $room = liveRoom($owner);
    $file = UploadedFile::fake()->createWithContent('session.webm', 'WEBM-BYTES');

    $res = $this->post('/studio/live1234/'.LIVE_KEY.'/recordings', ['video' => $file], ['Accept' => 'application/json'])
        ->assertCreated();

    $recId = $res->json('recording.id');
    expect($recId)->toBeInt();
    expect(Recording::count())->toBe(1);

    // Unauthenticated download is forbidden.
    $this->get('/recordings/'.$recId.'/video')->assertForbidden();

    // Another user is forbidden.
    $other = User::create([
        'name' => 'Other',
        'email' => 'other@example.com',
        'password' => 'secret123',
    ]);
    $this->actingAs($other)->get('/recordings/'.$recId.'/video')->assertForbidden();

    // Owner can stream the recording.
    $this->actingAs($owner)->get('/recordings/'.$recId.'/video')
        ->assertOk()
        ->assertHeader('Content-Type', 'video/webm');
});

test('recording upload fails when storage is not configured', function () {
    config(['greenroom.storage_url' => null]);
    liveRoom();
    $file = UploadedFile::fake()->createWithContent('session.webm', 'WEBM-BYTES');

    $this->post('/studio/live1234/'.LIVE_KEY.'/recordings', ['video' => $file], ['Accept' => 'application/json'])
        ->assertStatus(500);
});

test('signaling exchanges messages between guest and presenter with auth checks', function () {
    liveRoom();

    // Guest joins by signaling presenter.
    $this->postJson('/r/live1234/signals', [
        'sender' => 'guest-1',
        'recipient' => 'presenter',
        'kind' => 'join',
        'payload' => '{}',
    ])->assertCreated();

    // Guest cannot signal another guest.
    $this->postJson('/r/live1234/signals', [
        'sender' => 'guest-1',
        'recipient' => 'guest-2',
        'kind' => 'offer',
        'payload' => '{}',
    ])->assertForbidden();

    // Presenter signal requires valid key.
    $this->postJson('/r/live1234/signals', [
        'sender' => 'presenter',
        'recipient' => 'guest-1',
        'kind' => 'offer',
        'payload' => '{"sdp":"v=0"}',
        'key' => 'wrong',
    ])->assertForbidden();

    $this->postJson('/r/live1234/signals', [
        'sender' => 'presenter',
        'recipient' => 'guest-1',
        'kind' => 'offer',
        'payload' => '{"sdp":"v=0"}',
        'key' => LIVE_KEY,
    ])->assertCreated();

    // Polling presenter mailbox requires key.
    $this->getJson('/r/live1234/signals?recipient=presenter')->assertForbidden();
    $this->getJson('/r/live1234/signals?recipient=presenter&key='.LIVE_KEY)
        ->assertOk()
        ->assertJsonCount(1, 'signals')
        ->assertJsonPath('signals.0.sender', 'guest-1');

    // Guest polls its own mailbox.
    $this->getJson('/r/live1234/signals?recipient=guest-1')
        ->assertOk()
        ->assertJsonCount(1, 'signals')
        ->assertJsonPath('signals.0.kind', 'offer');
});
