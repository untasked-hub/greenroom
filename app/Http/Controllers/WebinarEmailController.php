<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Room;
use App\Models\WebinarRegistration;
use App\Services\DefaultEmailTemplateCatalog;
use App\Services\WebinarEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebinarEmailController extends Controller
{
    public function __construct(private readonly WebinarEmailService $emails)
    {
    }

    public function index(string $slug, string $key): Response
    {
        $room = $this->presenterRoom($slug, $key);
        $templates = $this->emails->templates($room);
        $recording = $room->latestRecording();

        $registrations = $room->registrations()
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (WebinarRegistration $reg) => [
                'id' => $reg->id,
                'name' => $reg->name,
                'email' => $reg->email,
                'registration_type' => $reg->registration_type,
                'attended_at' => $reg->attended_at?->toIso8601String(),
                'replay_followup_sent_at' => $reg->replay_followup_sent_at?->toIso8601String(),
                'created_at' => $reg->created_at?->toIso8601String(),
                'replay_url' => route('replay.show', [$room->slug, $reg->token]),
            ])
            ->values();

        $logs = $room->emailLogs()
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (EmailLog $log) => [
                'id' => $log->id,
                'template_key' => $log->template_key,
                'recipient_name' => $log->recipient_name,
                'recipient_email' => $log->recipient_email,
                'subject' => $log->subject,
                'body_html' => $log->body_html,
                'video_url' => $log->video_url,
                'has_recording' => $log->recording_id !== null,
                'status' => $log->status,
                'delivery_note' => $log->delivery_note,
                'sent_at' => $log->sent_at?->toIso8601String(),
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->values();

        $mailer = (string) config('mail.default', 'log');

        return Inertia::render('StudioEmails', [
            'room' => [
                'slug' => $room->slug,
                'title' => $room->title,
                'description' => $room->description ?? '',
                'email_language' => $this->emails->languageFor($room),
                'scheduled_at' => $room->scheduled_at?->toIso8601String(),
                'is_live' => $room->isLive(),
                'has_ended' => $room->hasEnded(),
                'live_ended_at' => $room->live_ended_at?->toIso8601String(),
                'reminder_dispatched_at' => $room->reminder_dispatched_at?->toIso8601String(),
                'followup_dispatched_at' => $room->followup_dispatched_at?->toIso8601String(),
                'has_recording' => $recording !== null,
                'recording_size' => $recording?->size,
            ],
            'presenterKey' => $key,
            'registrationUrl' => route('webinar.register', $room->slug),
            'guestUrl' => route('room', $room->slug),
            'studioUrl' => route('studio', [$room->slug, $key]),
            'templates' => $templates,
            'registrations' => $registrations,
            'logs' => $logs,
            'mailerMode' => in_array($mailer, ['log', 'array'], true) ? 'outbox' : $mailer,
            'languages' => DefaultEmailTemplateCatalog::availableLanguages(),
            'emailLanguage' => $this->emails->languageFor($room),
            'placeholders' => [
                ['token' => '{{name}}', 'label' => 'Registrant name', 'description' => 'Registrant name'],
                ['token' => '{{webinar_title}}', 'label' => 'Webinar title', 'description' => 'Webinar title'],
                ['token' => '{{scheduled_at}}', 'label' => 'Scheduled date & time', 'description' => 'Scheduled date & time'],
                ['token' => '{{join_url}}', 'label' => 'Live room link', 'description' => 'Live room link'],
                ['token' => '{{replay_url}}', 'label' => 'Replay watch page link', 'description' => 'Replay watch page link'],
                ['token' => '{{video_url}}', 'label' => 'Direct recorded video link', 'description' => 'Direct recorded video link'],
            ],
        ]);
    }

    public function updateSchedule(string $slug, string $key, Request $request): RedirectResponse
    {
        $room = $this->presenterRoom($slug, $key);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scheduled_at' => ['nullable', 'date'],
            'email_language' => ['sometimes', 'string', 'in:'.implode(',', DefaultEmailTemplateCatalog::languageCodes())],
        ]);

        $updates = [
            'description' => isset($data['description']) ? trim((string) $data['description']) : $room->description,
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ];

        if (isset($data['title'])) {
            $updates['title'] = trim($data['title']);
        }

        // If rescheduled to a new future time, allow the 10-minute reminder to fire again.
        if (array_key_exists('scheduled_at', $data) && $data['scheduled_at'] !== null) {
            $newTime = \Illuminate\Support\Carbon::parse($data['scheduled_at']);
            if ($room->scheduled_at === null || ! $room->scheduled_at->equalTo($newTime)) {
                $updates['reminder_dispatched_at'] = null;
            }
        }

        $room->update($updates);

        if (isset($data['email_language']) && $data['email_language'] !== $this->emails->languageFor($room)) {
            $this->emails->setLanguage($room->fresh(), $data['email_language']);
        }

        return back();
    }

    public function updateTemplate(string $slug, string $key, string $templateKey, Request $request): JsonResponse
    {
        $room = $this->presenterRoom($slug, $key);
        abort_unless(array_key_exists($templateKey, EmailTemplate::definitions()), 404);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'subject' => ['required', 'string', 'max:200'],
            'body_html' => ['required', 'string', 'max:20000'],
            'attach_recording' => ['sometimes', 'boolean'],
        ]);

        $this->emails->updateTemplate($room, $templateKey, $data);
        $templates = $this->emails->templates($room);

        return response()->json([
            'template' => collect($templates)->firstWhere('key', $templateKey),
            'templates' => $templates,
        ]);
    }

    public function resetTemplate(string $slug, string $key, string $templateKey): JsonResponse
    {
        $room = $this->presenterRoom($slug, $key);
        abort_unless(array_key_exists($templateKey, EmailTemplate::definitions()), 404);

        $this->emails->resetTemplate($room, $templateKey);
        $templates = $this->emails->templates($room);

        return response()->json([
            'template' => collect($templates)->firstWhere('key', $templateKey),
            'templates' => $templates,
        ]);
    }

    public function previewTemplate(string $slug, string $key, string $templateKey): JsonResponse
    {
        $room = $this->presenterRoom($slug, $key);
        abort_unless(array_key_exists($templateKey, EmailTemplate::definitions()), 404);

        return response()->json([
            'preview' => $this->emails->preview($room, $templateKey),
        ]);
    }

    public function setLanguage(string $slug, string $key, Request $request): JsonResponse
    {
        $room = $this->presenterRoom($slug, $key);

        $data = $request->validate([
            'language' => ['required', 'string', 'in:'.implode(',', DefaultEmailTemplateCatalog::languageCodes())],
            'reset_all' => ['sometimes', 'boolean'],
        ]);

        $language = $this->emails->setLanguage($room, $data['language'], (bool) ($data['reset_all'] ?? false));

        return response()->json([
            'emailLanguage' => $language,
            'templates' => $this->emails->templates($room->fresh()),
        ]);
    }

    public function dispatchNow(string $slug, string $key, Request $request): JsonResponse
    {
        $room = $this->presenterRoom($slug, $key);

        $data = $request->validate([
            'kind' => ['required', 'in:reminder,followups'],
        ]);

        if ($data['kind'] === 'reminder') {
            // Allow manual resend by clearing dispatched marker if host explicitly triggers it.
            $room->update(['reminder_dispatched_at' => null]);
            $count = $this->emails->dispatchReminder($room->fresh());
        } else {
            $room->update(['followup_dispatched_at' => null]);
            $count = $this->emails->dispatchFollowups($room->fresh());
        }

        return response()->json([
            'dispatched' => $count,
            'reminder_dispatched_at' => $room->fresh()->reminder_dispatched_at?->toIso8601String(),
            'followup_dispatched_at' => $room->fresh()->followup_dispatched_at?->toIso8601String(),
        ]);
    }

    private function presenterRoom(string $slug, string $key): Room
    {
        $room = Room::where('slug', $slug)->first();
        abort_if($room === null || ! $room->isPresenterKey($key), 404);

        return $room;
    }
}
