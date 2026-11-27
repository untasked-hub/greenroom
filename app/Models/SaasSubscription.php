<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaasSubscription extends Model
{
    protected $fillable = [
        'stripe_subscription_id', 'stripe_customer_id', 'plan', 'status', 'period_end', 'stripe_event_at',
    ];

    protected $casts = ['period_end' => 'datetime', 'stripe_event_at' => 'integer'];
}
