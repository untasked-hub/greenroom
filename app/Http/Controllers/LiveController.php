<?php

namespace App\Http\Controllers;

use App\Filesystem\UntaskedStorageAdapter;
use App\Models\Recording;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;

class LiveController extends Controller
{
    public const ALLOWED_RECORDING_MIMES = [
        'video/webm',
        'video/mp4',
        'application/octet-stream',
    ];

    public function goLive(string $slug, string $key): JsonResponse
    {
        $room = $this->presenterRoom($slug, $key);

        if (! $room->isLive()) {
            $room->update([
                'live_started_at' => now(),
                'live_ended_at' => null,
            ]);
            // Clear stale signaling rows from any previous run.
            $room->signals()->delete();
        }

        return response()->json(['live' => $this->liveState($room->fresh())]);
    }

    public function endLive(string $slug, string $key): JsonResponse
    {
        $room = $this->presenterRoom($slug, $key);

        if ($room->isLive()) {
            $room->update(['live_ended_at' => now()]);
        }

        return response()->json(['live' => $this->liveState($room->fresh())]);
    }

    public function updateLayout(string $slug, string $key, Request $request): JsonResponse
    {
        $room = $this->presenterRoom($slug, $key);

        $data = $request->validate([
            'stage_source' => ['sometimes', 'in:slide,screen'],
            'speaker_layout' => ['sometimes', 'in:sidebar,overlay,hidden'],
            'current_slide_page' => ['sometimes', 'integer', 'min:1', 'max:2000'],
        ]);

        $room->update($data);

        return response()->json(['live' => $this->liveState($room->fresh())]);
    }

    public function storeRecording(string $slug, string $key, Request $request): JsonResponse
    {
        $room = $this->presenterRoom($slug, $key);

        $maxKb = (int) config('greenroom.max_recording_kb', 204800);
        $data = $request->validate([
            'video' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', self::ALLOWED_RECORDING_MIMES)],
        ]);

        $file = $data['video'];
        $ext = strtolower($file->getClientOriginalExtension() ?: 'webm');
        if (! in_array($ext, ['webm', 'mp4'], true)) {
            $ext = 'webm';
        }
        $mime = $ext === 'mp4' ? 'video/mp4' : 'video/webm';
        $storageKey = 'recordings/'.$room->slug.'/'.Str::uuid()->toString().'.'.$ext;

        $this->storage()->put($storageKey, file_get_contents($file->getRealPath()));

        $recording = $room->recordings()->create([
            'storage_key' => $storageKey,
            'mime' => $mime,
            'size' => $file->getSize(),
            'started_at' => $room->live_started_at ?? now(),
            'ended_at' => $room->live_ended_at ?? now(),
        ]);

        return response()->json([
            'recording' => [
                'id' => $recording->id,
                'size' => $recording->size,
                'mime' => $recording->mime,
            ],
        ], 201);
    }

    public function downloadRecording(int $recording, Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $rec = Recording::with('room')->findOrFail($recording);
        abort_unless($rec->room && $rec->room->user_id === $user->id, 403);

        $bytes = $this->storage()->get($rec->storage_key);

        return response($bytes, 200, [
            'Content-Type' => $rec->mime ?: 'video/webm',
            'Content-Disposition' => 'inline; filename="greenroom-'.$rec->room->slug.'-'.$rec->id.'.'.($rec->mime === 'video/mp4' ? 'mp4' : 'webm').'"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    private function liveState(Room $room): array
    {
        return [
            'is_live' => $room->isLive(),
            'live_started_at' => $room->live_started_at?->toIso8601String(),
            'live_ended_at' => $room->live_ended_at?->toIso8601String(),
            'stage_source' => $room->stage_source ?? 'slide',
            'speaker_layout' => $room->speaker_layout ?? 'sidebar',
            'current_slide_page' => $room->current_slide_page ?? 1,
        ];
    }

    private function presenterRoom(string $slug, string $key): Room
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null || ! $room->isPresenterKey($key), 404);

        return $room;
    }

    private function storage(): UntaskedStorageAdapter
    {
        $url = (string) config('greenroom.storage_url');
        if ($url === '') {
            throw new RuntimeException('Recording storage is not configured.');
        }

        return new UntaskedStorageAdapter($url, (string) config('greenroom.storage_token'));
    }
}
