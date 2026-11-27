<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// Optional accounts. Guests and presenters can use Greenroom without one.
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    public function saasSubscriptions(): HasMany
    {
        return $this->hasMany(SaasSubscription::class);
    }

    public function saasCredits(): HasMany
    {
        return $this->hasMany(SaasCredit::class);
    }

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
