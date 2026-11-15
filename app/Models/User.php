<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Traits\Auditable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasMedia
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasRoles, InteractsWithMedia, Notifiable;

    protected $fillable = [
        'name', 'userName', 'email', 'password', 'status', 'referral_codes',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['super-admin', 'admin']);
    }

    /**
     * Company-level finance reports (the Finance Reports cluster and their CSV exports):
     * admins and the finance role, with `reports.view`. Managers and agents also hold
     * `reports.view` for game reports, so the role check keeps them out.
     */
    public function canViewFinanceReports(): bool
    {
        return $this->hasAnyRole(['super-admin', 'admin', 'finance']) && $this->hasPermissionTo('reports.view');
    }

    /**
     * Set the phone attribute - convert 0|+254 prefix to 254 for storage
     */
    public function setPhoneAttribute($value): void
    {
        $phone = trim($value);
        // If phone starts with +254, replace with 254
        if (str_starts_with($phone, '+254')) {
            $phone = '254'.substr($phone, 4);
        }
        // If phone starts with 0, replace with 254
        elseif (str_starts_with($phone, '0')) {
            $phone = '254'.substr($phone, 1);
        }
        $this->attributes['phone'] = $phone;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
    }

    public function holder(): HasOne
    {
        return $this->hasOne(Holder::class, 'user_id');
    }

    public function getReferralCodesArrayAttribute(): array
    {
        return $this->referral_codes
            ? json_decode($this->referral_codes, true) ?? []
            : [];
    }
}
