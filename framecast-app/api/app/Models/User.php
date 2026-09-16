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
    /**
     * Seats an agency can hand out on one of its client workspaces.
     *
     * Ordered, and the order is the permission model: a seat may do everything
     * the seat below it may. 'client' keeps its original spelling because it
     * was already issued before the other two existed, and renaming a role
     * string silently demotes everyone holding it.
     */
    public const ROLE_CLIENT_VIEWER = 'client';
    public const ROLE_CLIENT_EDITOR = 'client_editor';
    public const ROLE_CLIENT_ADMIN = 'client_admin';

    public const CLIENT_SEATS = [
        self::ROLE_CLIENT_VIEWER => 0,
        self::ROLE_CLIENT_EDITOR => 1,
        self::ROLE_CLIENT_ADMIN => 2,
    ];

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
     * Anyone holding a seat on an agency's client workspace.
     *
     * They sign in like anyone else and see one workspace — the one they were
     * invited to — but they never see the agency above it. What they may do
     * inside it depends on the seat; that the credits being spent are the
     * agency's does not, which is why every seat is pinned to one workspace
     * and refused the agency's own surfaces in AuthenticateWithJwt.
     */
    public function isClientSeat(): bool
    {
        return array_key_exists((string) $this->role, self::CLIENT_SEATS);
    }

    /** 0 viewer, 1 editor, 2 admin. -1 when this user holds no client seat. */
    public function clientSeatLevel(): int
    {
        return self::CLIENT_SEATS[(string) $this->role] ?? -1;
    }

    /** Kept for readers that only care about the read-only case. */
    public function isClientViewer(): bool
    {
        return $this->role === self::ROLE_CLIENT_VIEWER;
    }

}
