<?php

use App\Models\Room;
use App\Models\User;
use App\Models\WebinarRegistration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('my events separates upcoming and past webinars and classifies returning vs first-time attendees', function () {
    $host = User::create(['name' => 'Maya Host', 'email' => 'maya@example.com', 'password' => 'secret123']);
    $otherHost = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'secret123']);

    $pastRoom = Room::create([
        'slug' => 'past-webinar-1',
        'title' => 'Design Systems 101',
        'description' => 'Past session',
        'presenter_key_hash' => hash('sha256', 'past-key-1'),
        'user_id' => $host->id,
        'scheduled_at' => now()->subDays(5),
        'live_started_at' => now()->subDays(5),
        'live_ended_at' => now()->subDays(5)->addHour(),
    ]);

    $pastRoom->recordings()->create([
        'storage_key' => 'recordings/past-webinar-1/rec1.webm',
        'mime' => 'video/webm',
        'size' => 204800,
        'started_at' => now()->subDays(5),
        'ended_at' => now()->subDays(5)->addHour(),
    ]);

    $upcomingRoom = Room::create([
        'slug' => 'upcoming-webinar-2',
        'title' => 'Color & Typography Workshop',
        'description' => 'Upcoming session',
        'presenter_key_hash' => hash('sha256', 'upcoming-key-2'),
        'user_id' => $host->id,
        'scheduled_at' => now()->addDays(3),
    ]);

    // Unrelated host's room should not appear.
    Room::create([
        'slug' => 'other-host-room',
        'title' => 'Not Mine',
        'presenter_key_hash' => hash('sha256', 'other-key'),
        'user_id' => $otherHost->id,
    ]);

    // Returning attendee: registered for both past and upcoming events (case-insensitive email).
    WebinarRegistration::create([
        'room_id' => $pastRoom->id,
        'name' => 'Clara Returning',
        'email' => 'clara@example.com',
        'registration_type' => WebinarRegistration::TYPE_LIVE,
        'token' => 'tok-clara-1',
        'attended_at' => now()->subDays(5),
    ]);
    WebinarRegistration::create([
        'room_id' => $upcomingRoom->id,
        'name' => 'Clara Returning',
        'email' => 'CLARA@example.com',
        'registration_type' => WebinarRegistration::TYPE_LIVE,
        'token' => 'tok-clara-2',
    ]);

    // First-time attendee: registered only for the upcoming event.
    WebinarRegistration::create([
        'room_id' => $upcomingRoom->id,
        'name' => 'Leo FirstTime',
        'email' => 'leo@example.com',
        'registration_type' => WebinarRegistration::TYPE_LIVE,
        'token' => 'tok-leo-1',
    ]);

    // Duplicate registration (live + replay) for same email in same event should deduplicate.
    WebinarRegistration::create([
        'room_id' => $pastRoom->id,
        'name' => 'Clara Returning',
        'email' => 'clara@example.com',
        'registration_type' => WebinarRegistration::TYPE_REPLAY,
        'token' => 'tok-clara-replay',
    ]);

    $this->actingAs($host)
        ->get('/events')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('MyEvents')
            ->has('upcoming', 1)
            ->has('past', 1)
            ->where('stats.total_events', 2)
            ->where('stats.upcoming_count', 1)
            ->where('stats.past_count', 1)
            ->where('stats.unique_attendees', 2)
            ->where('stats.returning_attendees', 1)
            ->where('stats.recordings_count', 1)
            ->where('upcoming.0.slug', 'upcoming-webinar-2')
            ->where('upcoming.0.attendees_count', 2)
            ->where('upcoming.0.returning_count', 1)
            ->where('upcoming.0.first_time_count', 1)
            ->where('past.0.slug', 'past-webinar-1')
            ->where('past.0.attendees_count', 1)
            ->where('past.0.returning_count', 1)
            ->has('past.0.recordings', 1)
        );

    // Direct email settings URL and backstage studio URL from /events work without re-entering studio.
    $upcomingRoom->refresh();
    $key = $upcomingRoom->ensurePresenterKey();
    $this->actingAs($host)->get("/studio/upcoming-webinar-2/{$key}/emails")->assertOk();
    $this->actingAs($host)->get("/studio/upcoming-webinar-2/{$key}")->assertOk();
});

test('host can edit event cover card theme, badge, description, and cover image and see updates on registration page', function () {
    Storage::fake('local');
    config(['greenroom.storage_url' => '']);

    $host = User::create(['name' => 'Host One', 'email' => 'host1@example.com', 'password' => 'secret123']);
    $other = User::create(['name' => 'Host Two', 'email' => 'host2@example.com', 'password' => 'secret123']);

    $room = Room::create([
        'slug' => 'edit-cover-room',
        'title' => 'Original Title',
        'description' => 'Old description',
        'presenter_key_hash' => hash('sha256', 'k-edit'),
        'user_id' => $host->id,
    ]);

    // Another user cannot edit this event.
    $this->actingAs($other)
        ->postJson('/events/edit-cover-room', ['title' => 'Hacked'])
        ->assertNotFound();

    // Unauthenticated user cannot edit.
    auth()->logout();
    $this->postJson('/events/edit-cover-room', ['title' => 'Anon'])
        ->assertForbidden();

    // Host updates details + uploads a cover image.
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $file = UploadedFile::fake()->createWithContent('cover.png', $pngBytes);

    $this->actingAs($host)
        ->postJson('/events/edit-cover-room', [
            'title' => 'Editorial Typography Live',
            'description' => 'Learn grid systems and expressive type.',
            'cover_theme' => 'lilac',
            'cover_badge' => 'Live Workshop',
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'cover_image' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('event.title', 'Editorial Typography Live')
        ->assertJsonPath('event.cover_theme', 'lilac')
        ->assertJsonPath('event.cover_badge', 'Live Workshop')
        ->assertJsonPath('event.has_cover_image', true);

    // Public cover endpoint serves the uploaded image.
    $this->get('/r/edit-cover-room/cover')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    // Registration page reflects updated cover theme, badge, description, and image URL.
    $this->get('/r/edit-cover-room/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('RegisterWebinar')
            ->where('room.title', 'Editorial Typography Live')
            ->where('room.description', 'Learn grid systems and expressive type.')
            ->where('room.cover_theme', 'lilac')
            ->where('room.cover_badge', 'Live Workshop')
            ->where('room.cover_image_url', fn ($url) => is_string($url) && str_contains($url, '/r/edit-cover-room/cover'))
        );

    // Host can remove the cover image.
    $this->actingAs($host)
        ->postJson('/events/edit-cover-room', [
            'title' => 'Editorial Typography Live',
            'description' => 'Learn grid systems and expressive type.',
            'cover_theme' => 'emerald',
            'remove_cover_image' => true,
        ])
        ->assertOk()
        ->assertJsonPath('event.cover_theme', 'emerald')
        ->assertJsonPath('event.has_cover_image', false);

    $this->get('/r/edit-cover-room/cover')->assertNotFound();
});

test('event cover image uses Untasked storage adapter when storage_url is configured', function () {
    config(['greenroom.storage_url' => 'https://storage.test', 'greenroom.storage_token' => 'tok']);
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    \Illuminate\Support\Facades\Http::fake([
        'https://storage.test/objects/*' => \Illuminate\Support\Facades\Http::sequence()
            ->push('ok', 200)
            ->push($pngBytes, 200)
            ->push('ok', 200),
    ]);

    $host = User::create(['name' => 'Storage Host', 'email' => 'storagehost@example.com', 'password' => 'secret123']);
    Room::create([
        'slug' => 'storage-cover-room',
        'title' => 'Cloud Cover',
        'presenter_key_hash' => hash('sha256', 'k-storage'),
        'user_id' => $host->id,
    ]);

    $file = UploadedFile::fake()->createWithContent('cover.png', $pngBytes);

    $this->actingAs($host)
        ->post('/events/storage-cover-room', [
            'title' => 'Cloud Cover Updated',
            'cover_theme' => 'charcoal',
            'cover_image' => $file,
        ])
        ->assertRedirect();

    $this->get('/r/storage-cover-room/cover')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $this->actingAs($host)
        ->postJson('/events/storage-cover-room', [
            'title' => 'Cloud Cover Updated',
            'remove_cover_image' => true,
        ])
        ->assertOk()
        ->assertJsonPath('event.has_cover_image', false);
});
