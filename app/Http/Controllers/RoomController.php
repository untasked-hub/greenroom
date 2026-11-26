<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'scheduled_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        do {
            $slug = Str::lower(Str::random(8));
        } while (Room::where('slug', $slug)->exists());

        $presenterKey = Str::random(40);

        Room::create([
            'slug' => $slug,
            'title' => $data['title'],
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'cover_theme' => 'pistachio',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'presenter_key_hash' => hash('sha256', $presenterKey),
            'presenter_key_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($presenterKey),
            'user_id' => $request->user()?->id,
        ]);

        return redirect()->route('studio', [$slug, $presenterKey]);
    }

    public function show(string $slug, Request $request): Response
    {
        $room = $this->findRoom($slug);

        $regToken = trim((string) $request->query('reg', $request->session()->get('registration_token_'.$room->slug, '')));
        if ($regToken !== '') {
            $registration = $room->registrations()->where('token', $regToken)->first();
            if ($registration !== null) {
                $request->session()->put('registration_token_'.$room->slug, $registration->token);
                if (! $request->session()->has('guest_name') || $request->session()->get('guest_name') === '') {
                    $request->session()->put('guest_name', $registration->name);
                }
                if ($registration->attended_at === null) {
                    $registration->update(['attended_at' => now()]);
                }
            }
        }

        return Inertia::render('Room', [
            'room' => [
                'slug' => $room->slug,
                'title' => $room->title,
                'description' => $room->description,
                'scheduled_at' => $room->scheduled_at?->toIso8601String(),
                'is_live' => $room->isLive(),
                'live_started_at' => $room->live_started_at?->toIso8601String(),
                'live_ended_at' => $room->live_ended_at?->toIso8601String(),
                'stage_source' => $room->stage_source ?? 'slide',
                'speaker_layout' => $room->speaker_layout ?? 'sidebar',
                'current_slide_page' => $room->current_slide_page ?? 1,
            ],
            'guestName' => $request->session()->get('guest_name', ''),
            'registrationUrl' => route('webinar.register', $room->slug),
            'slide' => $this->slidePayload($room->currentSlide()),
        ]);
    }

    public function join(string $slug, Request $request): RedirectResponse
    {
        $room = $this->findRoom($slug);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $request->session()->put('guest_name', $data['name']);

        $regToken = (string) $request->session()->get('registration_token_'.$room->slug, '');
        if ($regToken !== '') {
            $registration = $room->registrations()->where('token', $regToken)->first();
            if ($registration !== null && $registration->attended_at === null) {
                $registration->update(['attended_at' => now()]);
            }
        }

        return redirect()->route('room', $slug);
    }

    public function studio(string $slug, string $key): Response
    {
        $room = $this->findRoom($slug, $key);

        if ($room->presenter_key_encrypted === null) {
            Room::whereKey($room->id)
                ->where('presenter_key_hash', $room->presenter_key_hash)
                ->whereNull('presenter_key_encrypted')
                ->update([
                    'presenter_key_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($key),
                ]);
        }

        return Inertia::render('Studio', [
            'room' => [
                'slug' => $room->slug,
                'title' => $room->title,
                'description' => $room->description ?? '',
                'scheduled_at' => $room->scheduled_at?->toIso8601String(),
                'is_live' => $room->isLive(),
                'live_started_at' => $room->live_started_at?->toIso8601String(),
                'live_ended_at' => $room->live_ended_at?->toIso8601String(),
                'stage_source' => $room->stage_source ?? 'slide',
                'speaker_layout' => $room->speaker_layout ?? 'sidebar',
                'current_slide_page' => $room->current_slide_page ?? 1,
                'has_owner_account' => $room->user_id !== null,
                'registrations_count' => $room->registrations()->count(),
            ],
            'presenterKey' => $key,
            'registrationUrl' => route('webinar.register', $room->slug),
            'emailsUrl' => route('studio.emails', [$room->slug, $key]),
            'currentSlideId' => $room->current_slide_id,
            'slides' => $room->slides()->orderBy('id')->get()->map(fn ($slide) => [
                'id' => $slide->id,
                'name' => $slide->name,
                'mime' => $slide->mime,
                'size' => $slide->size,
            ])->values(),
            'maxSlideKb' => (int) config('greenroom.max_slide_kb'),
        ]);
    }

    public function rotatePresenterKey(string $slug, string $key): RedirectResponse
    {
        $newKey = DB::transaction(function () use ($slug, $key) {
            return $this->findRoom($slug, $key, lockForUpdate: true)->rotatePresenterKey();
        });

        return redirect()->route('studio', [$slug, $newKey], 303);
    }

    public function setCurrentSlide(string $slug, string $key, Request $request): RedirectResponse
    {
        $room = $this->findRoom($slug, $key);

        $data = $request->validate([
            'slide_id' => ['nullable', 'integer'],
        ]);

        $slideId = $data['slide_id'] ?? null;
        if ($slideId !== null) {
            abort_unless($room->slides()->whereKey($slideId)->exists(), 404);
        }

        $room->update([
            'current_slide_id' => $slideId,
            'current_slide_page' => 1,
        ]);

        return back();
    }

    private function findRoom(string $slug, ?string $presenterKey = null, bool $lockForUpdate = false): Room
    {
        $query = Room::where('slug', $slug);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $room = $query->first();
        abort_if($room === null, 404);

        if ($presenterKey !== null) {
            abort_unless($room->isPresenterKey($presenterKey), 404);
        }

        return $room;
    }

    private function slidePayload($slide): ?array
    {
        return $slide === null ? null : [
            'id' => $slide->id,
            'name' => $slide->name,
            'mime' => $slide->mime,
        ];
    }
}
