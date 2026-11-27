<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignalController extends Controller
{
    public const ALLOWED_KINDS = ['join', 'offer', 'answer', 'ice', 'bye'];

    public function store(string $slug, Request $request): JsonResponse
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null, 404);

        abort_if($room->passTimeExpired(), 403);

        $data = $request->validate([
            'sender' => ['required', 'string', 'max:64'],
            'recipient' => ['required', 'string', 'max:64'],
            'kind' => ['required', 'in:'.implode(',', self::ALLOWED_KINDS)],
            'payload' => ['nullable', 'string', 'max:32000'],
            'key' => ['nullable', 'string'],
        ]);

        // Presenter-originated signals require a valid presenter key.
        if ($data['sender'] === 'presenter') {
            abort_unless($room->isPresenterKey((string) ($data['key'] ?? '')), 403);
        } else {
            // Guests can only signal the presenter.
            abort_unless($data['recipient'] === 'presenter', 403);
        }

        $signal = $room->signals()->create([
            'sender' => $data['sender'],
            'recipient' => $data['recipient'],
            'kind' => $data['kind'],
            'payload' => $data['payload'] ?? '{}',
        ]);

        // Keep table tidy by pruning old signals beyond 500 rows for this room.
        $cutoff = $room->signals()->orderByDesc('id')->skip(500)->value('id');
        if ($cutoff !== null) {
            $room->signals()->where('id', '<=', $cutoff)->delete();
        }

        return response()->json(['id' => $signal->id], 201);
    }

    public function index(string $slug, Request $request): JsonResponse
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null, 404);

        $recipient = (string) $request->query('recipient', '');
        abort_if($recipient === '' || strlen($recipient) > 64, 422);

        if ($recipient === 'presenter') {
            abort_unless($room->isPresenterKey((string) $request->query('key', '')), 403);
        }

        $after = max(0, (int) $request->query('after', 0));

        $signals = $room->signals()
            ->where('recipient', $recipient)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'sender' => $s->sender,
                'kind' => $s->kind,
                'payload' => $s->payload,
            ])
            ->values();

        return response()->json(['signals' => $signals]);
    }
}
