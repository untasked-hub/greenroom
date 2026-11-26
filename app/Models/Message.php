<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    public const CHANNEL_ROOM = 'room';

    public const CHANNEL_BACKSTAGE = 'backstage';

    protected $fillable = ['room_id', 'channel', 'author_name', 'is_presenter', 'body', 'hidden', 'pinned'];

    protected $casts = [
        'is_presenter' => 'boolean',
        'hidden' => 'boolean',
        'pinned' => 'boolean',
    ];
}
