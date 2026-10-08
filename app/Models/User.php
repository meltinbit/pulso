<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'active_property_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'properties_limit' => 'integer',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function gaConnections(): HasMany
    {
        return $this->hasMany(GaConnection::class);
    }

    public function gaProperties(): HasMany
    {
        return $this->hasMany(GaProperty::class);
    }

    /**
     * The property the user is looking at: the one they last switched to, or
     * their first active property. Stored on the user rather than in the
     * session, because concurrent requests (polling, prefetch) write the whole
     * session back and could revert a switch made while they were in flight.
     */
    public function activeProperty(): ?GaProperty
    {
        if ($this->active_property_id) {
            $property = $this->gaProperties()->find($this->active_property_id);

            if ($property) {
                return $property;
            }
        }

        return $this->gaProperties()->where('is_active', true)->first();
    }

    public function funnels(): HasMany
    {
        return $this->hasMany(Funnel::class);
    }

    public function mcpToken(): HasOne
    {
        return $this->hasOne(McpToken::class);
    }
}
