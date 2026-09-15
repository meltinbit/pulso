<?php

namespace App\Models;

use Database\Factories\GaConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class GaConnection extends Model
{
    /** @use HasFactory<GaConnectionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'google_id',
        'google_email',
        'google_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'is_active',
        'authorized_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'authorized_at' => 'datetime',
        ];
    }

    public function getAccessTokenAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getRefreshTokenAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['refresh_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(GaProperty::class);
    }
}
