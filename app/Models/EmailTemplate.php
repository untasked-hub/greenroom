<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    public const KEY_REGISTRATION_CONFIRMATION = 'registration_confirmation';
    public const KEY_REPLAY_CONFIRMATION = 'replay_confirmation';
    public const KEY_LIVE_REMINDER = 'live_reminder';
    public const KEY_ATTENDEE_FOLLOWUP = 'attendee_followup';
    public const KEY_NON_ATTENDEE_FOLLOWUP = 'non_attendee_followup';
    public const KEY_REPLAY_FOLLOWUP = 'replay_followup';

    protected $fillable = [
        'room_id',
        'template_key',
        'enabled',
        'subject',
        'body_html',
        'attach_recording',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'attach_recording' => 'boolean',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Metadata and default content for all webinar lifecycle email templates.
     *
     * @return array<string, array{
     *   key: string,
     *   group: string,
     *   label: string,
     *   audience: string,
     *   timing: string,
     *   default_subject: string,
     *   default_body_html: string,
     *   attach_recording: bool
     * }>
     */
    public static function definitions(): array
    {
        return [
            self::KEY_REGISTRATION_CONFIRMATION => [
                'key' => self::KEY_REGISTRATION_CONFIRMATION,
                'group' => 'When registered',
                'label' => 'Registration confirmation',
                'audience' => 'Live registrants',
                'timing' => 'When registering for the webinar',
                'default_subject' => "You're registered for {{webinar_title}}",
                'default_body_html' => '<p>Hi {{name}},</p><p>Thanks for registering for <strong>{{webinar_title}}</strong>.</p><p><strong>Scheduled start:</strong> {{scheduled_at}}</p><p>When it is time to join, use your personal room link: <a href="{{join_url}}">{{join_url}}</a></p><p>We will send you a reminder 10 minutes before we go live.</p>',
                'attach_recording' => false,
            ],
            self::KEY_REPLAY_CONFIRMATION => [
                'key' => self::KEY_REPLAY_CONFIRMATION,
                'group' => 'When registered',
                'label' => 'Replay confirmation',
                'audience' => 'Replay registrants',
                'timing' => 'When registering for the replay',
                'default_subject' => 'Your replay for {{webinar_title}} is ready',
                'default_body_html' => '<p>Hi {{name}},</p><p>Here is your access to the recorded webinar <strong>{{webinar_title}}</strong>.</p><p><a href="{{replay_url}}">Watch the webinar replay</a></p><p>Direct recorded video link: <a href="{{video_url}}">{{video_url}}</a></p>',
                'attach_recording' => true,
            ],
            self::KEY_LIVE_REMINDER => [
                'key' => self::KEY_LIVE_REMINDER,
                'group' => 'Before the webinar starts',
                'label' => 'Reminder',
                'audience' => 'Live registrants',
                'timing' => '10 minutes before the webinar starts',
                'default_subject' => 'Starting in 10 minutes: {{webinar_title}}',
                'default_body_html' => '<p>Hi {{name}},</p><p><strong>{{webinar_title}}</strong> starts in 10 minutes ({{scheduled_at}}).</p><p>Join the live room here: <a href="{{join_url}}">{{join_url}}</a></p>',
                'attach_recording' => false,
            ],
            self::KEY_ATTENDEE_FOLLOWUP => [
                'key' => self::KEY_ATTENDEE_FOLLOWUP,
                'group' => 'After the webinar ends',
                'label' => 'Follow-up (Attendees)',
                'audience' => 'Attendees',
                'timing' => '30 minutes after the webinar ends',
                'default_subject' => 'Thanks for attending {{webinar_title}}',
                'default_body_html' => '<p>Hi {{name}},</p><p>Thank you for joining <strong>{{webinar_title}}</strong> live!</p><p>If you want to revisit any part of the session, watch the replay here: <a href="{{replay_url}}">{{replay_url}}</a></p>',
                'attach_recording' => true,
            ],
            self::KEY_NON_ATTENDEE_FOLLOWUP => [
                'key' => self::KEY_NON_ATTENDEE_FOLLOWUP,
                'group' => 'After the webinar ends',
                'label' => 'Follow-up (Non-attendees)',
                'audience' => 'Non-attendees',
                'timing' => '30 minutes after the webinar ends',
                'default_subject' => 'Sorry we missed you at {{webinar_title}}',
                'default_body_html' => '<p>Hi {{name}},</p><p>We missed you at <strong>{{webinar_title}}</strong>, so we saved the replay for you.</p><p>Watch the recorded session here: <a href="{{replay_url}}">{{replay_url}}</a></p>',
                'attach_recording' => true,
            ],
            self::KEY_REPLAY_FOLLOWUP => [
                'key' => self::KEY_REPLAY_FOLLOWUP,
                'group' => 'After the webinar ends',
                'label' => 'Replay follow-up',
                'audience' => 'Replay registrants',
                'timing' => '30 minutes after registering for the replay',
                'default_subject' => 'Follow-up & recording: {{webinar_title}}',
                'default_body_html' => '<p>Hi {{name}},</p><p>Hope you enjoyed watching <strong>{{webinar_title}}</strong>.</p><p>You can revisit the recorded webinar video anytime: <a href="{{video_url}}">{{video_url}}</a></p>',
                'attach_recording' => true,
            ],
        ];
    }
}
