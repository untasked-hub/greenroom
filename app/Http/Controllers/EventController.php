<?php

namespace App\Http\Controllers;

use App\Filesystem\UntaskedStorageAdapter;
use App\Models\Recording;
use App\Models\Room;
use App\Models\WebinarRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user === null) {
            return redirect()->route('login');
        }

        $rooms = Room::query()
            ->where('user_id', $user->id)
            ->with([
                'registrations' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'),
                'recordings' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'),
            ])
            ->withCount(['emailLogs', 'slides'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        // Compute returning vs first-time attendees across all of this host's events.
        // An attendee (identified by lowercase email) is "returning" if they have registered
        // for more than one distinct event (room_id) owned by this host.
        $emailRoomIds = [];
        foreach ($rooms as $room) {
            foreach ($room->registrations as $reg) {
                $email = strtolower(trim($reg->email));
                if ($email === '') {
                    continue;
                }
                $emailRoomIds[$email][$room->id] = true;
            }
        }

        $upcoming = [];
        $past = [];
        $totalUniqueEmails = count($emailRoomIds);
        $returningUniqueEmails = 0;
        foreach ($emailRoomIds as $roomSet) {
            if (count($roomSet) > 1) {
                $returningUniqueEmails++;
            }
        }

        foreach ($rooms as $room) {
            $presenterKey = $room->ensurePresenterKey();

            // Deduplicate registrations per email within the same event (e.g., live + replay),
            // keeping the richest record.
            $byEmail = [];
            foreach ($room->registrations as $reg) {
                $email = strtolower(trim($reg->email));
                if (! isset($byEmail[$email])) {
                    $eventCount = isset($emailRoomIds[$email]) ? count($emailRoomIds[$email]) : 1;
                    $byEmail[$email] = [
                        'id' => $reg->id,
                        'name' => $reg->name,
                        'email' => $reg->email,
                        'registration_type' => $reg->registration_type,
                        'attended' => $reg->attended_at !== null,
                        'attended_at' => $reg->attended_at?->toIso8601String(),
                        'registered_at' => $reg->created_at?->toIso8601String(),
                        'is_returning' => $eventCount > 1,
                        'events_count' => $eventCount,
                    ];
                } else {
                    if ($reg->attended_at !== null && ! $byEmail[$email]['attended']) {
                        $byEmail[$email]['attended'] = true;
                        $byEmail[$email]['attended_at'] = $reg->attended_at->toIso8601String();
                    }
                    if ($reg->registration_type === WebinarRegistration::TYPE_LIVE) {
                        $byEmail[$email]['registration_type'] = WebinarRegistration::TYPE_LIVE;
                    }
                }
            }

            $attendees = array_values($byEmail);
            $returningCount = count(array_filter($attendees, fn ($a) => $a['is_returning']));
            $firstTimeCount = count($attendees) - $returningCount;

            $recordings = $room->recordings->map(fn (Recording $rec) => [
                'id' => $rec->id,
                'size' => $rec->size,
                'mime' => $rec->mime ?: 'video/webm',
                'started_at' => $rec->started_at?->toIso8601String(),
                'ended_at' => $rec->ended_at?->toIso8601String(),
                'created_at' => $rec->created_at?->toIso8601String(),
                'video_url' => route('recordings.video', $rec->id),
            ])->values()->all();

            $isPast = $room->hasEnded()
                || ($room->scheduled_at !== null && $room->scheduled_at->isPast() && ! $room->isLive() && count($recordings) > 0);

            $status = $room->isLive()
                ? 'live'
                : ($isPast ? 'past' : 'upcoming');

            $item = [
                'id' => $room->id,
                'slug' => $room->slug,
                'title' => $room->title,
                'description' => $room->description ?? '',
                'cover_theme' => $room->normalizedCoverTheme(),
                'cover_badge' => $room->cover_badge ?? '',
                'cover_image_url' => $room->coverImageUrl(),
                'has_cover_image' => $room->cover_image_key !== null,
                'email_language' => $room->email_language ?: 'en',
                'scheduled_at' => $room->scheduled_at?->toIso8601String(),
                'live_started_at' => $room->live_started_at?->toIso8601String(),
                'live_ended_at' => $room->live_ended_at?->toIso8601String(),
                'created_at' => $room->created_at?->toIso8601String(),
                'status' => $status,
                'is_live' => $room->isLive(),
                'has_ended' => $room->hasEnded(),
                'slides_count' => (int) $room->slides_count,
                'email_logs_count' => (int) $room->email_logs_count,
                'attendees_count' => count($attendees),
                'returning_count' => $returningCount,
                'first_time_count' => $firstTimeCount,
                'attendees' => $attendees,
                'recordings' => $recordings,
                'studio_url' => route('studio', [$room->slug, $presenterKey]),
                'emails_url' => route('studio.emails', [$room->slug, $presenterKey]),
                'registration_url' => route('webinar.register', $room->slug),
                'guest_url' => route('room', $room->slug),
            ];

            if ($isPast) {
                $past[] = $item;
            } else {
                $upcoming[] = $item;
            }
        }

        // Sort upcoming: live first, then nearest scheduled_at ascending, then newest created_at.
        usort($upcoming, function (array $a, array $b): int {
            if ($a['is_live'] !== $b['is_live']) {
                return $a['is_live'] ? -1 : 1;
            }
            if ($a['scheduled_at'] !== null && $b['scheduled_at'] !== null) {
                return strcmp($a['scheduled_at'], $b['scheduled_at']);
            }
            if ($a['scheduled_at'] !== null) {
                return -1;
            }
            if ($b['scheduled_at'] !== null) {
                return 1;
            }

            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });

        // Sort past: most recently ended / created first.
        usort($past, function (array $a, array $b): int {
            $aTime = $a['live_ended_at'] ?? $a['scheduled_at'] ?? $a['created_at'] ?? '';
            $bTime = $b['live_ended_at'] ?? $b['scheduled_at'] ?? $b['created_at'] ?? '';

            return strcmp((string) $bTime, (string) $aTime);
        });

        return Inertia::render('MyEvents', [
            'upcoming' => $upcoming,
            'past' => $past,
            'coverThemes' => Room::COVER_THEMES,
            'stats' => [
                'total_events' => count($upcoming) + count($past),
                'upcoming_count' => count($upcoming),
                'past_count' => count($past),
                'unique_attendees' => $totalUniqueEmails,
                'returning_attendees' => $returningUniqueEmails,
                'recordings_count' => array_sum(array_map(fn ($e) => count($e['recordings']), $past))
                    + array_sum(array_map(fn ($e) => count($e['recordings']), $upcoming)),
            ],
        ]);
    }

    public function update(string $slug, Request $request): JsonResponse|RedirectResponse
    {
        $room = $this->ownedRoom($slug, $request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scheduled_at' => ['nullable', 'date'],
            'cover_theme' => ['nullable', 'string', 'in:'.implode(',', Room::COVER_THEMES)],
            'cover_badge' => ['nullable', 'string', 'max:60'],
            'cover_image' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'remove_cover_image' => ['nullable', 'boolean'],
        ]);

        $updates = [
            'title' => trim($data['title']),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'cover_theme' => $data['cover_theme'] ?? $room->normalizedCoverTheme(),
            'cover_badge' => isset($data['cover_badge']) && trim((string) $data['cover_badge']) !== ''
                ? trim((string) $data['cover_badge'])
                : null,
        ];

        if (array_key_exists('scheduled_at', $data)) {
            $updates['scheduled_at'] = $data['scheduled_at'];
            if ($data['scheduled_at'] !== null) {
                $newTime = \Illuminate\Support\Carbon::parse($data['scheduled_at']);
                if ($room->scheduled_at === null || ! $room->scheduled_at->equalTo($newTime)) {
                    $updates['reminder_dispatched_at'] = null;
                }
            }
        }

        if ($request->boolean('remove_cover_image') && $room->cover_image_key) {
            $this->deleteCoverBytes($room->cover_image_key);
            $updates['cover_image_key'] = null;
            $updates['cover_image_mime'] = null;
        }

        if ($request->hasFile('cover_image')) {
            $file = $request->file('cover_image');
            $bytes = file_get_contents($file->getRealPath());
            $mime = $file->getMimeType() ?: 'image/png';
            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => 'png',
            };
            $newKey = 'covers/'.$room->slug.'/'.Str::random(16).'.'.$ext;

            if ($room->cover_image_key) {
                $this->deleteCoverBytes($room->cover_image_key);
            }

            $this->putCoverBytes($newKey, $bytes, $mime);
            $updates['cover_image_key'] = $newKey;
            $updates['cover_image_mime'] = $mime;
        }

        $room->update($updates);
        $room->refresh();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'event' => [
                    'slug' => $room->slug,
                    'title' => $room->title,
                    'description' => $room->description ?? '',
                    'scheduled_at' => $room->scheduled_at?->toIso8601String(),
                    'cover_theme' => $room->normalizedCoverTheme(),
                    'cover_badge' => $room->cover_badge ?? '',
                    'cover_image_url' => $room->coverImageUrl(),
                    'has_cover_image' => $room->cover_image_key !== null,
                ],
            ]);
        }

        return back();
    }

    public function cover(string $slug): HttpResponse
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null || ! $room->cover_image_key, 404);

        $bytes = $this->getCoverBytes($room->cover_image_key);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => $room->cover_image_mime ?: 'image/png',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function ownedRoom(string $slug, Request $request): Room
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $room = Room::where('slug', $slug)
            ->where('user_id', $user->id)
            ->first();
        abort_if($room === null, 404);

        return $room;
    }

    private function putCoverBytes(string $key, string $bytes, string $mime): void
    {
        $url = (string) config('greenroom.storage_url');
        if ($url !== '') {
            $storage = new UntaskedStorageAdapter($url, (string) config('greenroom.storage_token'));
            $storage->put($key, $bytes, $mime);

            return;
        }

        Storage::disk('local')->put($key, $bytes);
    }

    private function getCoverBytes(string $key): ?string
    {
        $url = (string) config('greenroom.storage_url');
        if ($url !== '') {
            try {
                $storage = new UntaskedStorageAdapter($url, (string) config('greenroom.storage_token'));

                return $storage->get($key);
            } catch (\Throwable) {
                return null;
            }
        }

        if (! Storage::disk('local')->exists($key)) {
            return null;
        }

        return Storage::disk('local')->get($key);
    }

    private function deleteCoverBytes(string $key): void
    {
        $url = (string) config('greenroom.storage_url');
        if ($url !== '') {
            try {
                $storage = new UntaskedStorageAdapter($url, (string) config('greenroom.storage_token'));
                $storage->delete($key);
            } catch (\Throwable) {
                // Best-effort cleanup
            }

            return;
        }

        Storage::disk('local')->delete($key);
    }
}
