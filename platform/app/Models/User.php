<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'password', 'role', 'status',
        'preferred_locale', 'avatar_path', 'registration_ip',
        'phone_verified_at', 'email_verified_at',
    ];

    /**
     * Contact details are private by default: any endpoint that eager-loads a
     * user (order actor, tradesperson owner, reviewer...) would otherwise leak
     * that person's email and phone. Endpoints entitled to them opt in with
     * makeVisible().
     */
    protected $hidden = [
        'password', 'remember_token', 'email', 'phone', 'registration_ip',
        'email_verified_at', 'phone_verified_at',
    ];

    public const CONTACT_FIELDS = ['email', 'phone'];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function tradespersonProfile(): HasOne
    {
        return $this->hasOne(TradespersonProfile::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isStaff(): bool
    {
        return $this->role instanceof UserRole && $this->role->isStaff();
    }

    public function routeNotificationForWhatsApp(): string
    {
        return $this->phone;
    }
}
