<?php

use App\Models\Room;
use App\Models\Slide;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

const SLIDE_KEY = 'presenter-key-123';

function slideRoom(): Room
{
    return Room::create([
        'slug' => 'deck1234',
        'title' => 'Deck review',
        'presenter_key_hash' => hash('sha256', SLIDE_KEY),
    ]);
}

function fakeDeck(Room $room, string $name = 'deck.pdf'): Slide
{
    return $room->slides()->create([
        'name' => $name,
        'mime' => 'application/pdf',
        'storage_key' => 'slides/'.$room->slug.'/'.$name,
        'size' => 1234,
    ]);
}

beforeEach(function () {
    config(['greenroom.storage_url' => 'https://storage.test', 'greenroom.storage_token' => 'token']);
});

test('a presenter uploads a PDF deck to storage', function () {
    Http::fake(['https://storage.test/objects/*' => Http::response('ok', 200)]);
    $room = slideRoom();
    $file = UploadedFile::fake()->createWithContent('Q3 Deck.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

    $this->post('/studio/deck1234/'.SLIDE_KEY.'/slides', ['slides' => $file], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('slide.name', 'Q3 Deck.pdf')
        ->assertJsonPath('slide.mime', 'application/pdf');

    $slide = $room->slides()->firstOrFail();
    expect($slide->storage_key)->toStartWith('slides/deck1234/')->toEndWith('-q3-deck.pdf');
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/objects/slides/deck1234/'));
});

test('uploads that are not PDF or PPTX are rejected', function () {
    Http::fake();
    slideRoom();
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'just some text');

    $this->post('/studio/deck1234/'.SLIDE_KEY.'/slides', ['slides' => $file], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(Slide::count())->toBe(0);
    Http::assertNothingSent();
});

test('uploading needs the presenter key', function () {
    slideRoom();
    $file = UploadedFile::fake()->createWithContent('deck.pdf', "%PDF-1.4\n%%EOF\n");

    $this->post('/studio/deck1234/wrong/slides', ['slides' => $file], ['Accept' => 'application/json'])
        ->assertNotFound();
});

test('upload fails loudly when storage is not configured', function () {
    config(['greenroom.storage_url' => null]);
    slideRoom();
    $file = UploadedFile::fake()->createWithContent('deck.pdf', "%PDF-1.4\n%%EOF\n");

    $this->post('/studio/deck1234/'.SLIDE_KEY.'/slides', ['slides' => $file], ['Accept' => 'application/json'])
        ->assertStatus(500);
});

test('the presenter puts a deck live and guests can fetch it', function () {
    Http::fake(['https://storage.test/objects/*' => Http::response('%PDF-bytes', 200)]);
    $room = slideRoom();
    $slide = fakeDeck($room);

    $this->get('/r/deck1234/live')->assertNotFound();

    $this->post('/studio/deck1234/'.SLIDE_KEY.'/live', ['slide_id' => $slide->id])->assertRedirect();
    expect($room->fresh()->current_slide_id)->toBe($slide->id);

    $response = $this->get('/r/deck1234/live')->assertOk();
    expect($response->getContent())->toBe('%PDF-bytes');
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');

    $this->get('/r/deck1234')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Room')->where('slide.id', $slide->id));

    $this->get('/studio/deck1234/'.SLIDE_KEY)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Studio')
            ->where('currentSlideId', $slide->id)
            ->where('slides.0.name', 'deck.pdf'));
});

test('the presenter can clear the stage', function () {
    $room = slideRoom();
    $slide = fakeDeck($room);
    $room->update(['current_slide_id' => $slide->id]);

    $this->post('/studio/deck1234/'.SLIDE_KEY.'/live', ['slide_id' => null])->assertRedirect();

    expect($room->fresh()->current_slide_id)->toBeNull();
});

test('a deck from another room cannot be put live', function () {
    slideRoom();
    $other = Room::create(['slug' => 'other123', 'title' => 'Other', 'presenter_key_hash' => hash('sha256', 'x')]);
    $foreign = fakeDeck($other);

    $this->post('/studio/deck1234/'.SLIDE_KEY.'/live', ['slide_id' => $foreign->id])->assertNotFound();
    $this->post('/studio/deck1234/wrong/live', ['slide_id' => $foreign->id])->assertNotFound();
});

test('guests only download the live deck while presenters download any', function () {
    Http::fake(['https://storage.test/objects/*' => Http::response('bytes', 200)]);
    $room = slideRoom();
    $live = fakeDeck($room, 'live.pdf');
    $draft = fakeDeck($room, 'draft.pdf');
    $room->update(['current_slide_id' => $live->id]);

    $this->get("/r/deck1234/slides/{$live->id}")->assertOk();
    $this->get("/r/deck1234/slides/{$draft->id}")->assertNotFound();
    $this->get("/r/deck1234/slides/{$draft->id}?key=".SLIDE_KEY)->assertOk();
    $this->get("/r/missing/slides/{$live->id}")->assertNotFound();
    $this->get('/r/missing/live')->assertNotFound();
});
