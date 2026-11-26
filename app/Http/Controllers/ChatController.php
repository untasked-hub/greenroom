<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function index(string $slug, string $channel, Request $request): JsonResponse
    {
        $room = $this->findRoom($slug);
        $presenter = $this->isPresenter($room, $request);
        $this->ensureChannelAccess($channel, $presenter);

        $query = $room->messages()->where('channel', $channel)->orderByDesc('id')->limit(200);
        if (! $presenter) {
            $query->where('hidden', false);
        }

        $messages = $query->get()->reverse()->values()->map(fn (Message $message) => $this->payload($message));

        $currentSlide = $room->currentSlide();

        return response()->json([
            'messages' => $messages,
            'live' => [
                'is_live' => $room->isLive(),
                'live_started_at' => $room->live_started_at?->toIso8601String(),
                'live_ended_at' => $room->live_ended_at?->toIso8601String(),
                'stage_source' => $room->stage_source ?? 'slide',
                'speaker_layout' => $room->speaker_layout ?? 'sidebar',
                'current_slide_page' => $room->current_slide_page ?? 1,
                'slide' => $currentSlide ? [
                    'id' => $currentSlide->id,
                    'name' => $currentSlide->name,
                    'mime' => $currentSlide->mime,
                    'url' => route('room.slide', [$room->slug, $currentSlide->id]),
                ] : null,
            ],
        ]);
    }

    public function store(string $slug, string $channel, Request $request): JsonResponse
    {
        $room = $this->findRoom($slug);
        $presenter = $this->isPresenter($room, $request);
        $this->ensureChannelAccess($channel, $presenter);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:500'],
            'author' => ['nullable', 'string', 'max:60'],
        ]);

        $name = $presenter
            ? (trim((string) ($data['author'] ?? '')) ?: 'Presenter')
            : (string) $request->session()->get('guest_name', '');

        abort_if($name === '', 422, 'Join the room with a name before chatting.');

        $message = $room->messages()->create([
            'channel' => $channel,
            'author_name' => $name,
            'is_presenter' => $presenter,
            'body' => $data['body'],
        ]);

        return response()->json(['message' => $this->payload($message)], 201);
    }

    public function moderate(string $slug, int $message, string $action, Request $request): JsonResponse
    {
        $room = $this->findRoom($slug);
        abort_unless($this->isPresenter($room, $request), 403);
        abort_unless(in_array($action, ['hide', 'show', 'pin', 'unpin'], true), 404);

        $target = $room->messages()->whereKey($message)->firstOrFail();

        $changes = match ($action) {
            'hide' => ['hidden' => true],
            'show' => ['hidden' => false],
            'pin' => ['pinned' => true],
            'unpin' => ['pinned' => false],
        };
        $target->update($changes);

        return response()->json(['message' => $this->payload($target)]);
    }

    private function findRoom(string $slug): Room
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null, 404);

        return $room;
    }

    private function isPresenter(Room $room, Request $request): bool
    {
        return $room->isPresenterKey((string) $request->input('key', $request->query('key', '')));
    }

    private function ensureChannelAccess(string $channel, bool $presenter): void
    {
        abort_unless(in_array($channel, [Message::CHANNEL_ROOM, Message::CHANNEL_BACKSTAGE], true), 404);
        abort_if($channel === Message::CHANNEL_BACKSTAGE && ! $presenter, 403);
    }

    private function payload(Message $message): array
    {
        return [
            'id' => $message->id,
            'author_name' => $message->author_name,
            'body' => $message->body,
            'is_presenter' => $message->is_presenter,
            'hidden' => $message->hidden,
            'pinned' => $message->pinned,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
