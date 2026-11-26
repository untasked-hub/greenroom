<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Signal extends Model
{
    protected $fillable = ['room_id', 'sender', 'recipient', 'kind', 'payload'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
