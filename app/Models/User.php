<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password',
        'avatar',
        'upi_id',
        'google_id',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'otp_code',
        'otp_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function expensesPaid(): HasMany
    {
        return $this->hasMany(Expense::class, 'paid_by');
    }

    public function expenseShares(): HasMany
    {
        return $this->hasMany(ExpenseParticipant::class);
    }

    public function settlementsSent(): HasMany
    {
        return $this->hasMany(Settlement::class, 'from_user_id');
    }

    public function settlementsReceived(): HasMany
    {
        return $this->hasMany(Settlement::class, 'to_user_id');
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }
}
