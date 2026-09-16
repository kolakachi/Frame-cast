<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public const ROLE_CLIENT_VIEWER = 'client';

    protected $fillable = [
        'workspace_id',
        'name',
        'email',
        'password_hash',
        'timezone',
        'role',
        'status',
        'preferences_json',
        'onboarding_step',
        'onboarding_last_sent_at',
        'last_seen_at',
        'changelog_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'preferences_json' => 'array',
            'onboarding_step' => 'integer',
            'onboarding_last_sent_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'changelog_seen_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash ?? '';
    }

    /**
     * Always lowercase the stored email so 'Kola@Gmail.com' and 'kola@gmail.com'
     * resolve to the same account. RFC 5321 says the local-part is technically
     * case-sensitive, but every consumer mail provider treats it as not, and
     * real users routinely type the same address in different casings.
     */
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : strtolower($value);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * A client of an agency, invited to watch their own workspace.
     *
     * They sign in like anyone else and see one workspace — the one they were
     * invited to — but they are not a seat on the agency's team: the credits
     * belong to the agency, so a client who could spend them would be spending
     * someone else's money. Enforced in AuthenticateWithJwt, which refuses
     * every unsafe method this role has not been explicitly given.
     */
    public function isClientViewer(): bool
    {
        return $this->role === self::ROLE_CLIENT_VIEWER;
    }

}
