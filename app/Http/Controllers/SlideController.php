<?php

namespace App\Http\Controllers;

use App\Filesystem\UntaskedStorageAdapter;
use App\Models\Room;
use App\Models\Slide;
use App\Services\PptxSlideParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;

class SlideController extends Controller
{
    public const ALLOWED_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function store(string $slug, string $key, Request $request): JsonResponse
    {
        $room = $this->presenterRoom($slug, $key);

        $maxKb = (int) config('greenroom.max_slide_kb');
        $data = $request->validate([
            'slides' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', self::ALLOWED_MIMES)],
        ]);

        $file = $data['slides'];
        $storageKey = 'slides/'.$room->slug.'/'.Str::uuid()->toString().'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'slides').'.'.$file->getClientOriginalExtension();

        $this->storage()->put($storageKey, file_get_contents($file->getRealPath()));

        $slide = $room->slides()->create([
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'storage_key' => $storageKey,
            'size' => $file->getSize(),
        ]);

        return response()->json(['slide' => [
            'id' => $slide->id,
            'name' => $slide->name,
            'mime' => $slide->mime,
            'size' => $slide->size,
        ]], 201);
    }

    public function download(string $slug, int $slide, Request $request): Response
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null, 404);

        $key = (string) $request->query('key', '');
        if ($key !== '' && $room->isPresenterKey($key)) {
            $target = $room->slides()->whereKey($slide)->firstOrFail();
        } else {
            // Guests may only fetch the slide the presenter has put live.
            abort_unless($room->current_slide_id === $slide, 404);
            $target = $room->slides()->whereKey($slide)->firstOrFail();
        }

        return $this->deckResponse($target);
    }

    public function live(string $slug): Response
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null, 404);

        $current = $room->currentSlide();
        abort_if($current === null, 404);

        return $this->deckResponse($current);
    }

    public function slidesData(string $slug, int $slide, Request $request, PptxSlideParser $parser): JsonResponse
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null, 404);

        $key = (string) $request->query('key', '');
        if ($key !== '' && $room->isPresenterKey($key)) {
            $target = $room->slides()->whereKey($slide)->firstOrFail();
        } else {
            abort_unless($room->current_slide_id === $slide, 404);
            $target = $room->slides()->whereKey($slide)->firstOrFail();
        }

        $bytes = $this->storage()->get($target->storage_key);
        $slides = $parser->parseBytes($bytes);

        return response()->json([
            'id' => $target->id,
            'name' => $target->name,
            'mime' => $target->mime,
            'slides' => $slides,
        ]);
    }

    private function deckResponse(Slide $slide): Response
    {
        $bytes = $this->storage()->get($slide->storage_key);

        return response($bytes, 200, [
            'Content-Type' => $slide->mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($slide->name).'"',
            'Cache-Control' => 'private, max-age=60',
        ]);
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
            throw new RuntimeException('Slide storage is not configured.');
        }

        return new UntaskedStorageAdapter($url, (string) config('greenroom.storage_token'));
    }
}
