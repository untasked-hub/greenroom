<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaasCredit extends Model
{
    protected $fillable = ['type', 'stripe_checkout_session_id', 'room_id', 'consumed_at'];

    protected $casts = ['consumed_at' => 'datetime'];
}
