<?php

namespace App\Http\Controllers;

use App\Filesystem\UntaskedStorageAdapter;
use App\Models\EmailTemplate;
use App\Models\Room;
use App\Models\WebinarRegistration;
use App\Services\WebinarEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class RegistrationController extends Controller
{
    public function __construct(private readonly WebinarEmailService $emails)
    {
    }

    public function show(string $slug, Request $request): Response
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null, 404);

        $recording = $room->latestRecording();
        $sessionToken = (string) $request->session()->get('registration_token_'.$room->slug, '');
        $registration = $sessionToken !== ''
            ? $room->registrations()->where('token', $sessionToken)->first()
            : null;

        return Inertia::render('RegisterWebinar', [
            'room' => [
                'slug' => $room->slug,
                'title' => $room->title,
                'description' => $room->description,
                'cover_theme' => $room->normalizedCoverTheme(),
                'cover_badge' => $room->cover_badge,
                'cover_image_url' => $room->coverImageUrl(),
                'scheduled_at' => $room->scheduled_at?->toIso8601String(),
                'is_live' => $room->isLive(),
                'has_ended' => $room->hasEnded(),
                'live_ended_at' => $room->live_ended_at?->toIso8601String(),
                'has_recording' => $recording !== null,
            ],
            'registration' => $registration ? [
                'name' => $registration->name,
                'email' => $registration->email,
                'registration_type' => $registration->registration_type,
                'join_url' => route('room', $room->slug).'?reg='.$registration->token,
                'replay_url' => route('replay.show', [$room->slug, $registration->token]),
            ] : null,
            'defaultType' => $room->hasEnded() ? WebinarRegistration::TYPE_REPLAY : WebinarRegistration::TYPE_LIVE,
        ]);
    }

    public function store(string $slug, Request $request): RedirectResponse
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'registration_type' => ['nullable', 'in:live,replay'],
        ]);

        $type = $data['registration_type']
            ?? ($room->hasEnded() ? WebinarRegistration::TYPE_REPLAY : WebinarRegistration::TYPE_LIVE);
        $email = strtolower(trim($data['email']));
        $name = trim($data['name']);

        $register = function () use ($room, $email, $type, $name) {
            if (config('saas.enabled')) {
                Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            }

            $registration = WebinarRegistration::where('room_id', $room->id)
                ->where('email', $email)
                ->where('registration_type', $type)
                ->first();

            if ($registration === null) {
                if (config('saas.enabled') && $room->registrations()->count() >= config('saas.attendee_limit')) {
                    return null;
                }

                $registration = $room->registrations()->create([
                    'name' => $name,
                    'email' => $email,
                    'registration_type' => $type,
                    'token' => Str::random(48),
                ]);
            } else {
                $registration->update(['name' => $name]);
            }

            return $registration;
        };

        $registration = config('saas.enabled') ? DB::transaction($register, 3) : $register();
        if ($registration === null) {
            return back()->withErrors(['email' => 'This webinar has reached its registration limit.']);
        }

        $templateKey = $type === WebinarRegistration::TYPE_REPLAY
            ? EmailTemplate::KEY_REPLAY_CONFIRMATION
            : EmailTemplate::KEY_REGISTRATION_CONFIRMATION;

        $this->emails->sendForRegistration($room, $registration, $templateKey);

        $request->session()->put('guest_name', $name);
        $request->session()->put('registration_token_'.$room->slug, $registration->token);

        if ($type === WebinarRegistration::TYPE_REPLAY) {
            return redirect()->route('replay.show', [$room->slug, $registration->token]);
        }

        return redirect()->route('webinar.register', $room->slug);
    }

    public function replay(string $slug, string $token): Response
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null, 404);

        $registration = $room->registrations()->where('token', $token)->first();
        abort_if($registration === null, 404);

        $recording = $room->latestRecording();

        return Inertia::render('Replay', [
            'room' => [
                'slug' => $room->slug,
                'title' => $room->title,
                'description' => $room->description,
                'scheduled_at' => $room->scheduled_at?->toIso8601String(),
                'live_ended_at' => $room->live_ended_at?->toIso8601String(),
            ],
            'registrant' => [
                'name' => $registration->name,
                'email' => $registration->email,
            ],
            'recording' => $recording ? [
                'id' => $recording->id,
                'mime' => $recording->mime ?: 'video/webm',
                'size' => $recording->size,
                'video_url' => route('replay.video', [$room->slug, $registration->token]),
                'created_at' => $recording->created_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function replayVideo(string $slug, string $token): HttpResponse
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null, 404);

        $registration = $room->registrations()->where('token', $token)->first();
        abort_if($registration === null, 404);

        $recording = $room->latestRecording();
        abort_if($recording === null, 404);

        $url = (string) config('greenroom.storage_url');
        if ($url === '') {
            throw new RuntimeException('Recording storage is not configured.');
        }

        $storage = new UntaskedStorageAdapter($url, (string) config('greenroom.storage_token'));
        $bytes = $storage->get($recording->storage_key);
        $ext = $recording->mime === 'video/mp4' ? 'mp4' : 'webm';

        return response($bytes, 200, [
            'Content-Type' => $recording->mime ?: 'video/webm',
            'Content-Disposition' => 'inline; filename="greenroom-'.$room->slug.'-replay.'.$ext.'"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
