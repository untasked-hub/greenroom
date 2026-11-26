<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebinarRegistration extends Model
{
    public const TYPE_LIVE = 'live';
    public const TYPE_REPLAY = 'replay';

    protected $fillable = [
        'room_id',
        'name',
        'email',
        'registration_type',
        'token',
        'attended_at',
        'replay_followup_sent_at',
    ];

    protected $casts = [
        'attended_at' => 'datetime',
        'replay_followup_sent_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'registration_id');
    }
}
