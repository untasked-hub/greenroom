<?php

namespace App\Services;

use App\Filesystem\UntaskedStorageAdapter;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Recording;
use App\Models\Room;
use App\Models\WebinarRegistration;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Renders and delivers the webinar lifecycle emails (registration, reminder,
 * follow-ups) and records every message in the email log so presenters can see
 * what was sent, when, and to whom.
 */
class WebinarEmailService
{
    public function ensureTemplates(Room $room): void
    {
        foreach (EmailTemplate::definitions() as $key => $definition) {
            $default = $this->defaultDefinition($room, $key);

            EmailTemplate::firstOrCreate(
                ['room_id' => $room->id, 'template_key' => $key],
                [
                    'enabled' => true,
                    'subject' => $default['default_subject'],
                    'body_html' => $default['default_body_html'],
                    'attach_recording' => $default['attach_recording'],
                ]
            );
        }
    }

    /**
     * Language-aware default content for one template key, read from the
     * database (default_email_templates) with the code definitions as fallback.
     *
     * @return array{group:string,label:string,audience:string,timing:string,default_subject:string,default_body_html:string,attach_recording:bool}
     */
    public function defaultDefinition(Room $room, string $key): array
    {
        $base = EmailTemplate::definitions()[$key] ?? null;
        if ($base === null) {
            throw new RuntimeException('Unknown email template.');
        }

        $row = DefaultEmailTemplateCatalog::defaultFor($this->languageFor($room), $key);

        return [
            'group' => $base['group'],
            'label' => $base['label'],
            'audience' => $base['audience'],
            'timing' => $base['timing'],
            'default_subject' => $row?->subject ?? $base['default_subject'],
            'default_body_html' => $row?->body_html ?? $base['default_body_html'],
            'attach_recording' => $row?->attach_recording ?? $base['attach_recording'],
        ];
    }

    public function languageFor(Room $room): string
    {
        $code = (string) ($room->email_language ?? '');

        return in_array($code, DefaultEmailTemplateCatalog::languageCodes(), true)
            ? $code
            : DefaultEmailTemplateCatalog::DEFAULT_LANGUAGE;
    }

    /**
     * Switch the email campaign language for a room, persist it on the room,
     * and reload every uncustomized template in the newly selected language.
     */
    public function setLanguage(Room $room, string $languageCode, bool $resetAll = false): string
    {
        if (! in_array($languageCode, DefaultEmailTemplateCatalog::languageCodes(), true)) {
            throw new RuntimeException('Unsupported email language.');
        }

        $room->update(['email_language' => $languageCode]);
        $room->refresh();

        foreach (EmailTemplate::definitions() as $key => $definition) {
            $template = $room->emailTemplates()->where('template_key', $key)->first();
            $nextDefault = $this->defaultDefinition($room, $key);
            $subjectIsDefault = $template === null || $this->isDefaultSubject($key, $template->subject);
            $bodyIsDefault = $template === null || $this->isDefaultBodyHtml($key, $template->body_html);

            EmailTemplate::updateOrCreate(
                ['room_id' => $room->id, 'template_key' => $key],
                [
                    'enabled' => $template?->enabled ?? true,
                    'subject' => $resetAll || $subjectIsDefault ? $nextDefault['default_subject'] : $template->subject,
                    'body_html' => $resetAll || $bodyIsDefault ? $nextDefault['default_body_html'] : $template->body_html,
                    'attach_recording' => $resetAll || $template === null ? $nextDefault['attach_recording'] : $template->attach_recording,
                ]
            );
        }

        return $languageCode;
    }

    private function isDefaultSubject(string $key, string $subject): bool
    {
        $normalized = $this->normalizeTextForComparison($subject);
        if ($normalized === '') {
            return true;
        }

        foreach (DefaultEmailTemplateCatalog::allDefaultVariants($key)['subjects'] as $candidate) {
            if ($this->normalizeTextForComparison($candidate) === $normalized) {
                return true;
            }
        }

        return false;
    }

    private function isDefaultBodyHtml(string $key, string $bodyHtml): bool
    {
        $normalized = $this->normalizeHtmlForComparison($bodyHtml);
        if ($normalized === '') {
            return true;
        }

        foreach (DefaultEmailTemplateCatalog::allDefaultVariants($key)['bodies'] as $candidate) {
            if ($this->normalizeHtmlForComparison($candidate) === $normalized) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTextForComparison(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function normalizeHtmlForComparison(string $html): string
    {
        $normalized = preg_replace('/<br\s*\/?>/i', '<br>', $html) ?? $html;
        $normalized = preg_replace('/\s+(?:target|rel)="[^"]*"/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/>\s+</u', '><', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function templates(Room $room): array
    {
        $this->ensureTemplates($room);

        $stored = $room->emailTemplates()->get()->keyBy('template_key');
        $out = [];

        foreach (EmailTemplate::definitions() as $key => $definition) {
            $template = $stored->get($key);
            $default = $this->defaultDefinition($room, $key);
            $out[] = [
                'key' => $key,
                'group' => $definition['group'],
                'label' => $definition['label'],
                'audience' => $definition['audience'],
                'timing' => $definition['timing'],
                'default_subject' => $default['default_subject'],
                'default_body_html' => $default['default_body_html'],
                'subject' => $template?->subject ?? $default['default_subject'],
                'body_html' => $template?->body_html ?? $default['default_body_html'],
                'enabled' => $template?->enabled ?? true,
                'attach_recording' => $template?->attach_recording ?? $default['attach_recording'],
            ];
        }

        return $out;
    }

    public function updateTemplate(Room $room, string $key, array $data): void
    {
        $definition = EmailTemplate::definitions()[$key] ?? null;
        if ($definition === null) {
            throw new RuntimeException('Unknown email template.');
        }

        $default = $this->defaultDefinition($room, $key);

        $template = EmailTemplate::firstOrNew(['room_id' => $room->id, 'template_key' => $key]);
        $template->fill([
            'enabled' => (bool) ($data['enabled'] ?? $template->enabled ?? true),
            'subject' => $this->cleanSubject($data['subject'] ?? $template->subject ?? $default['default_subject']),
            'body_html' => $this->sanitizeHtml($data['body_html'] ?? $template->body_html ?? $default['default_body_html']),
            'attach_recording' => (bool) ($data['attach_recording'] ?? $template->attach_recording ?? false),
        ]);
        $template->save();
    }

    public function resetTemplate(Room $room, string $key): void
    {
        $definition = EmailTemplate::definitions()[$key] ?? null;
        if ($definition === null) {
            throw new RuntimeException('Unknown email template.');
        }

        $default = $this->defaultDefinition($room, $key);

        EmailTemplate::updateOrCreate(
            ['room_id' => $room->id, 'template_key' => $key],
            [
                'enabled' => true,
                'subject' => $default['default_subject'],
                'body_html' => $default['default_body_html'],
                'attach_recording' => $default['attach_recording'],
            ]
        );
    }

    /**
     * Send one lifecycle email for a registration and log the outcome.
     */
    public function sendForRegistration(Room $room, WebinarRegistration $registration, string $templateKey): ?EmailLog
    {
        $this->ensureTemplates($room);

        $template = $room->emailTemplates()->where('template_key', $templateKey)->first();
        if ($template === null || ! $template->enabled) {
            return null;
        }

        $recording = $template->attach_recording ? $this->replayRecording($room) : null;
        $variables = $this->variables($room, $registration, $recording);

        $subject = $this->render($template->subject, $variables);
        $body = $this->render($template->body_html, $variables);

        $log = new EmailLog([
            'room_id' => $room->id,
            'registration_id' => $registration->id,
            'template_key' => $templateKey,
            'recipient_name' => $registration->name,
            'recipient_email' => $registration->email,
            'subject' => mb_substr($subject, 0, 190),
            'body_html' => $body,
            'recording_id' => $recording?->id,
            'video_url' => $recording ? route('replay.video', [$room->slug, $registration->token]) : null,
            'status' => EmailLog::STATUS_LOGGED,
        ]);
        $log->save();

        $mailer = (string) config('mail.default', 'log');
        $isSimulated = in_array($mailer, ['log', 'array'], true);
        $attachedBytes = null;
        $attachedFilename = null;
        $attachedMime = null;
        $note = null;

        if ($recording !== null && $template->attach_recording) {
            $maxAttachBytes = 10 * 1024 * 1024;
            if (! $isSimulated && $recording->size <= $maxAttachBytes && config('greenroom.storage_url')) {
                try {
                    $storage = new UntaskedStorageAdapter(
                        (string) config('greenroom.storage_url'),
                        (string) config('greenroom.storage_token')
                    );
                    $attachedBytes = $storage->get($recording->storage_key);
                    $ext = $recording->mime === 'video/mp4' ? 'mp4' : 'webm';
                    $attachedFilename = 'greenroom-'.$room->slug.'-replay.'.$ext;
                    $attachedMime = $recording->mime ?: 'video/webm';
                } catch (Throwable $e) {
                    $note = 'Video link included; attachment skipped: '.mb_substr($e->getMessage(), 0, 140);
                }
            } else {
                $note = $isSimulated
                    ? 'Recorded in outbox (configure MAIL_MAILER=smtp for live delivery); replay video linked.'
                    : 'Replay video linked (file exceeds 10 MB attachment limit).';
            }
        } elseif ($isSimulated) {
            $note = 'Recorded in outbox (configure MAIL_MAILER=smtp for live delivery).';
        }

        try {
            Mail::html($this->wrap($room, $body), function ($message) use ($registration, $subject, $attachedBytes, $attachedFilename, $attachedMime) {
                $message->to($registration->email, $registration->name)
                    ->subject($subject);

                if ($attachedBytes !== null && $attachedFilename !== null) {
                    $message->attachData($attachedBytes, $attachedFilename, [
                        'mime' => $attachedMime ?? 'video/webm',
                    ]);
                }
            });

            $log->update([
                'status' => $isSimulated ? EmailLog::STATUS_LOGGED : EmailLog::STATUS_SENT,
                'delivery_note' => $note,
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            $log->update([
                'status' => EmailLog::STATUS_FAILED,
                'delivery_note' => mb_substr($e->getMessage(), 0, 250),
            ]);
        }

        return $log;
    }

    /**
     * Dispatch all due scheduled emails across all rooms:
     * - 10-minute live reminder before scheduled_at
     * - 30-minute post-webinar follow-up (attendees + non-attendees) after live_ended_at
     * - 30-minute replay follow-up after replay registration
     *
     * @return array{reminders: int, followups: int, replay_followups: int}
     */
    public function dispatchDue(): array
    {
        $now = now();
        $reminders = 0;
        $followups = 0;
        $replayFollowups = 0;

        Room::query()
            ->whereNotNull('scheduled_at')
            ->whereNull('reminder_dispatched_at')
            ->whereNull('live_started_at')
            ->where('scheduled_at', '<=', $now->copy()->addMinutes(10))
            ->where('scheduled_at', '>=', $now->copy()->subMinutes(15))
            ->each(function (Room $room) use (&$reminders) {
                $reminders += $this->dispatchReminder($room);
            });

        Room::query()
            ->whereNotNull('live_ended_at')
            ->whereNull('followup_dispatched_at')
            ->where('live_ended_at', '<=', $now->copy()->subMinutes(30))
            ->each(function (Room $room) use (&$followups) {
                $followups += $this->dispatchFollowups($room);
            });

        WebinarRegistration::query()
            ->where('registration_type', WebinarRegistration::TYPE_REPLAY)
            ->whereNull('replay_followup_sent_at')
            ->where('created_at', '<=', $now->copy()->subMinutes(30))
            ->with('room')
            ->each(function (WebinarRegistration $registration) use (&$replayFollowups) {
                if ($registration->room === null) {
                    return;
                }
                if ($this->sendForRegistration($registration->room, $registration, EmailTemplate::KEY_REPLAY_FOLLOWUP) !== null) {
                    $registration->update(['replay_followup_sent_at' => now()]);
                    $replayFollowups++;
                }
            });

        return [
            'reminders' => $reminders,
            'followups' => $followups,
            'replay_followups' => $replayFollowups,
        ];
    }

    /**
     * Send the 10-minute reminder to live registrants that have not had one yet.
     */
    public function dispatchReminder(Room $room): int
    {
        if ($room->reminder_dispatched_at !== null) {
            return 0;
        }

        $sent = 0;
        $room->registrations()
            ->where('registration_type', WebinarRegistration::TYPE_LIVE)
            ->each(function (WebinarRegistration $registration) use ($room, &$sent) {
                if ($this->sendForRegistration($room, $registration, EmailTemplate::KEY_LIVE_REMINDER) !== null) {
                    $sent++;
                }
            });

        $room->update(['reminder_dispatched_at' => now()]);

        return $sent;
    }

    /**
     * Send attendee / non-attendee follow-ups plus the replay-registrant follow-up.
     */
    public function dispatchFollowups(Room $room): int
    {
        if ($room->followup_dispatched_at !== null) {
            return 0;
        }

        $sent = 0;

        $room->registrations()
            ->where('registration_type', WebinarRegistration::TYPE_LIVE)
            ->each(function (WebinarRegistration $registration) use ($room, &$sent) {
                $key = $registration->attended_at !== null
                    ? EmailTemplate::KEY_ATTENDEE_FOLLOWUP
                    : EmailTemplate::KEY_NON_ATTENDEE_FOLLOWUP;

                if ($this->sendForRegistration($room, $registration, $key) !== null) {
                    $sent++;
                }
            });

        $room->registrations()
            ->where('registration_type', WebinarRegistration::TYPE_REPLAY)
            ->whereNull('replay_followup_sent_at')
            ->each(function (WebinarRegistration $registration) use ($room, &$sent) {
                if ($this->sendForRegistration($room, $registration, EmailTemplate::KEY_REPLAY_FOLLOWUP) !== null) {
                    $registration->update(['replay_followup_sent_at' => now()]);
                    $sent++;
                }
            });

        $room->update(['followup_dispatched_at' => now()]);

        return $sent;
    }

    /**
     * Render a preview of one template without queueing any email.
     *
     * @return array{subject: string, body_html: string, video_url: ?string}
     */
    public function preview(Room $room, string $templateKey, ?WebinarRegistration $registration = null): array
    {
        $this->ensureTemplates($room);

        $template = $room->emailTemplates()->where('template_key', $templateKey)->first();
        $definition = array_key_exists($templateKey, EmailTemplate::definitions())
            ? $this->defaultDefinition($room, $templateKey)
            : null;

        $subjectSource = $template?->subject ?? $definition['default_subject'] ?? '';
        $bodySource = $template?->body_html ?? $definition['default_body_html'] ?? '';
        $recording = $template?->attach_recording || ($definition['attach_recording'] ?? false)
            ? $this->replayRecording($room)
            : null;

        $registration ??= new WebinarRegistration([
            'room_id' => $room->id,
            'name' => 'Sam Presenter',
            'email' => 'sam@example.com',
            'registration_type' => WebinarRegistration::TYPE_LIVE,
            'token' => 'preview-token',
        ]);

        $variables = $this->variables($room, $registration, $recording);

        return [
            'subject' => $this->render($subjectSource, $variables),
            'body_html' => $this->render($bodySource, $variables),
            'video_url' => $recording ? route('replay.video', [$room->slug, $registration->token]) : null,
        ];
    }

    public function replayRecording(Room $room): ?Recording
    {
        return $room->recordings()->orderByDesc('id')->first();
    }

    /**
     * @return array<string, string>
     */
    public function variables(Room $room, WebinarRegistration $registration, ?Recording $recording): array
    {
        $replayUrl = route('replay.show', [$room->slug, $registration->token]);
        $videoUrl = $recording
            ? route('replay.video', [$room->slug, $registration->token])
            : '';

        return [
            '{{name}}' => e($registration->name),
            '{{email}}' => e($registration->email),
            '{{webinar_title}}' => e($room->title),
            '{{webinar_description}}' => e((string) ($room->description ?? '')),
            '{{scheduled_at}}' => $room->scheduled_at?->toDayDateTimeString() ?? 'To be announced',
            '{{join_url}}' => route('room', $room->slug),
            '{{replay_url}}' => $replayUrl,
            '{{video_url}}' => $videoUrl !== '' ? $videoUrl : $replayUrl,
            '{{studio_note}}' => '',
        ];
    }

    private function render(string $template, array $variables): string
    {
        return strtr($template, $variables);
    }

    private function wrap(Room $room, string $body): string
    {
        $title = e($room->title);
        $app = e((string) config('app.name', 'Greenroom'));
        $rtl = $this->languageFor($room) === 'ar';
        $dir = $rtl ? 'rtl' : 'ltr';
        $align = $rtl ? 'right' : 'left';

        return '<!doctype html><html dir="'.$dir.'" lang="'.$this->languageFor($room).'"><body style="margin:0;background:#f2f7f1;padding:24px;'
            .'font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1f3227;">'
            .'<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #dbe8d8;'
            .'border-radius:20px;padding:28px;text-align:'.$align.';">'
            .'<div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#4d8a63;'
            .'margin-bottom:12px;">'.$app.' · '.$title.'</div>'
            .'<div style="font-size:15px;line-height:1.65;color:#243b2c;">'.$body.'</div>'
            .'<div style="margin-top:24px;padding-top:16px;border-top:1px solid #e3eee1;font-size:12px;'
            .'color:#6d8a78;">'.$app.' — '.$title.'</div>'
            .'</div></body></html>';
    }

    private function cleanSubject(string $subject): string
    {
        return trim(strip_tags($subject));
    }

    /**
     * Keep TipTap output but strip anything that can execute in a mail client.
     */
    private function sanitizeHtml(string $html): string
    {
        $html = preg_replace('#<\s*(script|style|iframe|object|embed)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<\s*(script|iframe|object|embed)[^>]*/?\s*>#i', '', $html) ?? $html;
        $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        $html = preg_replace('#javascript:#i', '', $html) ?? $html;

        return Str::of($html)->trim()->__toString();
    }
}