<?php

use App\Models\Message;
use App\Models\Room;

function makeRoom(): Room
{
    return Room::create([
        'slug' => 'abc12345',
        'title' => 'Launch AMA',
        'presenter_key_hash' => hash('sha256', 'presenter-key-123'),
    ]);
}

test('home renders the Greenroom welcome page', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Welcome')->where('title', 'Greenroom'));
});

test('creating a room redirects to a presenter studio', function () {
    $response = $this->post('/rooms', ['title' => 'Demo day']);

    $room = Room::where('title', 'Demo day')->firstOrFail();
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/studio/'.$room->slug.'/');
});

test('the studio rejects a wrong presenter key', function () {
    makeRoom();

    $this->get('/studio/abc12345/wrong-key')->assertNotFound();
});

test('the studio renders for the right presenter key', function () {
    makeRoom();

    $this->get('/studio/abc12345/presenter-key-123')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Studio')->where('room.slug', 'abc12345'));
});

test('resetting the studio link replaces the presenter key', function () {
    $room = makeRoom();
    $oldKey = 'presenter-key-123';

    $response = $this->post("/studio/{$room->slug}/{$oldKey}/rotate")->assertRedirect();

    $newKey = $room->refresh()->getPresenterKey();
    expect($newKey)->not->toBeNull()->not->toBe($oldKey);
    $response->assertRedirect(route('studio', [$room->slug, $newKey]));

    $this->get(route('studio', [$room->slug, $oldKey]))->assertNotFound();
    $this->post("/studio/{$room->slug}/{$oldKey}/rotate")->assertNotFound();
    $this->get($response->headers->get('Location'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Studio')->where('presenterKey', $newKey));
});

test('a wrong presenter key cannot reset the studio link', function () {
    $room = makeRoom();
    $room->ensurePresenterKey();
    $hash = $room->presenter_key_hash;
    $encrypted = $room->presenter_key_encrypted;

    $this->get("/studio/{$room->slug}/wrong-key")->assertNotFound();
    $this->post("/studio/{$room->slug}/wrong-key/rotate")->assertNotFound();

    expect($room->refresh()->presenter_key_hash)->toBe($hash)
        ->and($room->presenter_key_encrypted)->toBe($encrypted);
});

test('unknown rooms return 404', function () {
    $this->get('/r/missing')->assertNotFound();
    $this->getJson('/r/missing/chat/room')->assertNotFound();
});

test('a guest joins with a name and sees the room', function () {
    makeRoom();

    $this->post('/r/abc12345/join', ['name' => 'Ana'])->assertRedirect('/r/abc12345');
    $this->get('/r/abc12345')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Room')->where('guestName', 'Ana'));
});

test('a guest cannot post without joining', function () {
    makeRoom();

    $this->postJson('/r/abc12345/chat/room', ['body' => 'hi'])->assertStatus(422);
});

test('guest messages appear in the room chat after joining', function () {
    makeRoom();
    $this->post('/r/abc12345/join', ['name' => 'Ana']);

    $this->postJson('/r/abc12345/chat/room', ['body' => 'Hello panel'])->assertCreated();

    $this->getJson('/r/abc12345/chat/room')
        ->assertOk()
        ->assertJsonPath('messages.0.body', 'Hello panel')
        ->assertJsonPath('messages.0.author_name', 'Ana')
        ->assertJsonPath('messages.0.is_presenter', false);
});

test('guests cannot read or write the backstage channel', function () {
    makeRoom();
    $this->post('/r/abc12345/join', ['name' => 'Ana']);

    $this->getJson('/r/abc12345/chat/backstage')->assertForbidden();
    $this->postJson('/r/abc12345/chat/backstage', ['body' => 'psst'])->assertForbidden();
});

test('unknown chat channels are rejected', function () {
    makeRoom();

    $this->getJson('/r/abc12345/chat/nope')->assertNotFound();
});

test('presenter backstage chat is private to the presenter key', function () {
    makeRoom();

    $this->postJson('/r/abc12345/chat/backstage', [
        'key' => 'presenter-key-123',
        'body' => 'Run of show: intro then demo',
        'author' => 'Sam',
    ])->assertCreated();

    $this->getJson('/r/abc12345/chat/backstage?key=presenter-key-123')
        ->assertOk()
        ->assertJsonPath('messages.0.body', 'Run of show: intro then demo')
        ->assertJsonPath('messages.0.is_presenter', true);

    $this->getJson('/r/abc12345/chat/backstage')->assertForbidden();
});

test('presenter messages default their author name', function () {
    makeRoom();

    $this->postJson('/r/abc12345/chat/room', ['key' => 'presenter-key-123', 'body' => 'Welcome!'])
        ->assertCreated()
        ->assertJsonPath('message.author_name', 'Presenter');
});

test('presenter can hide, show, pin and unpin a guest message', function () {
    makeRoom();
    $this->post('/r/abc12345/join', ['name' => 'Ana']);
    $this->postJson('/r/abc12345/chat/room', ['body' => 'Spam link'])->assertCreated();
    $message = Message::firstOrFail();

    $this->postJson("/r/abc12345/moderate/{$message->id}/hide", ['key' => 'presenter-key-123'])->assertOk();
    expect($message->fresh()->hidden)->toBeTrue();
    $this->getJson('/r/abc12345/chat/room')->assertJsonCount(0, 'messages');

    $this->postJson("/r/abc12345/moderate/{$message->id}/show", ['key' => 'presenter-key-123'])->assertOk();
    $this->postJson("/r/abc12345/moderate/{$message->id}/pin", ['key' => 'presenter-key-123'])->assertOk();
    expect($message->fresh()->pinned)->toBeTrue();
    $this->postJson("/r/abc12345/moderate/{$message->id}/unpin", ['key' => 'presenter-key-123'])->assertOk();
    expect($message->fresh()->pinned)->toBeFalse();
});

test('guests cannot moderate messages', function () {
    makeRoom();
    $this->post('/r/abc12345/join', ['name' => 'Ana']);
    $this->postJson('/r/abc12345/chat/room', ['body' => 'Hi'])->assertCreated();
    $message = Message::firstOrFail();

    $this->postJson("/r/abc12345/moderate/{$message->id}/hide")->assertForbidden();
    expect($message->fresh()->hidden)->toBeFalse();
});

test('unknown moderation actions return 404', function () {
    makeRoom();
    $this->post('/r/abc12345/join', ['name' => 'Ana']);
    $this->postJson('/r/abc12345/chat/room', ['body' => 'Hi'])->assertCreated();
    $message = Message::firstOrFail();

    $this->postJson("/r/abc12345/moderate/{$message->id}/explode", ['key' => 'presenter-key-123'])->assertNotFound();
});
