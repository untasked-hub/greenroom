<?php

use App\Models\Room;
use App\Models\User;
use App\Services\PptxSlideParser;
use Illuminate\Support\Facades\Http;

function makeMinimalPptxBytes(array $slides): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'pptx_test_');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($slides as $i => $paragraphs) {
        $num = $i + 1;
        $pXml = '';
        foreach ($paragraphs as $p) {
            $pXml .= '<a:p><a:r><a:t>'.htmlspecialchars($p, ENT_XML1).'</a:t></a:r></a:p>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<p:cSld><p:spTree><p:sp><p:txBody>'.$pXml.'</p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
        $zip->addFromString("ppt/slides/slide{$num}.xml", $xml);
    }
    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    return $bytes ?: '';
}

test('user can register, log out, log in, and redirects to My events', function () {
    $this->get('/register')->assertOk();
    $this->get('/login')->assertOk();

    $this->post('/register', [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect('/events');

    $user = User::where('email', 'alice@example.com')->firstOrFail();

    // Authenticated user visiting /login or /register redirects to /events.
    $this->get('/login')->assertRedirect('/events');
    $this->get('/register')->assertRedirect('/events');

    // Legacy /recordings redirects to /events.
    $this->get('/recordings')->assertRedirect('/events');

    $this->post('/logout')->assertRedirect('/');

    // Unauthenticated /events and /recordings redirect to /login.
    $this->get('/events')->assertRedirect('/login');
    $this->get('/recordings')->assertRedirect('/login');

    // Invalid login fails.
    $this->post('/login', [
        'email' => 'alice@example.com',
        'password' => 'wrongpass',
    ])->assertSessionHasErrors('email');

    // Valid login succeeds.
    $this->post('/login', [
        'email' => 'alice@example.com',
        'password' => 'password123',
    ])->assertRedirect('/events');
});

test('pptx slide parser extracts ordered slides and handles invalid bytes', function () {
    $parser = new PptxSlideParser;
    expect($parser->parseBytes('not-a-zip'))->toBe([]);

    $bytes = makeMinimalPptxBytes([
        ['Welcome to Greenroom', 'Agenda item 1', 'Agenda item 2'],
        ['Q&A', 'Ask in guest chat'],
    ]);

    $parsed = $parser->parseBytes($bytes);
    expect($parsed)->toHaveCount(2);
    expect($parsed[0]['title'])->toBe('Welcome to Greenroom');
    expect($parsed[0]['lines'])->toBe(['Agenda item 1', 'Agenda item 2']);
    expect($parsed[1]['title'])->toBe('Q&A');
});

test('slidesData endpoint returns parsed PPTX slides for live deck and presenter key', function () {
    config(['greenroom.storage_url' => 'https://storage.test', 'greenroom.storage_token' => 'token']);
    $bytes = makeMinimalPptxBytes([['Slide One', 'Bullet A']]);
    Http::fake(['https://storage.test/objects/*' => Http::response($bytes, 200)]);

    $room = Room::create([
        'slug' => 'pptx1234',
        'title' => 'Deck Talk',
        'presenter_key_hash' => hash('sha256', 'pkey'),
    ]);
    $slide = $room->slides()->create([
        'name' => 'talk.pptx',
        'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'storage_key' => 'slides/pptx1234/talk.pptx',
        'size' => strlen($bytes),
    ]);

    // Guest gets 404 before slide is live.
    $this->getJson('/r/pptx1234/slides/'.$slide->id.'/pages')->assertNotFound();

    // Presenter with key can fetch before live.
    $this->getJson('/r/pptx1234/slides/'.$slide->id.'/pages?key=pkey')
        ->assertOk()
        ->assertJsonPath('slides.0.title', 'Slide One')
        ->assertJsonPath('slides.0.lines.0', 'Bullet A');

    // Once put live, guest can fetch.
    $room->update(['current_slide_id' => $slide->id]);
    $this->getJson('/r/pptx1234/slides/'.$slide->id.'/pages')
        ->assertOk()
        ->assertJsonPath('slides.0.title', 'Slide One');
});
