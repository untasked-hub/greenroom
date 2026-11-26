<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    public const STATUS_SENT = 'sent';
    public const STATUS_LOGGED = 'logged';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'room_id',
        'registration_id',
        'template_key',
        'recipient_name',
        'recipient_email',
        'subject',
        'body_html',
        'recording_id',
        'video_url',
        'status',
        'delivery_note',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(WebinarRegistration::class, 'registration_id');
    }

    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class);
    }
}
