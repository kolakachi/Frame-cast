<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AppSumo lifetime-deal license. AppSumo owns/regenerates the key; we store
 * it (searchable by support), track its tier + status, and remember whether the
 * one-time credit bucket has been granted so re-sent webhooks / re-activations
 * never double-grant.
 */
class AppSumoLicense extends Model
{
    // Class name snake-cases to "app_sumo_licenses"; the migration creates
    // "appsumo_licenses". Pin it explicitly so Eloquent hits the real table.
    protected $table = 'appsumo_licenses';

    protected $fillable = [
        'license_key',
        'workspace_id',
        'tier',
        'appsumo_tier',
        'status',
        'granted_credits',
        'last_payload',
    ];

    protected $casts = [
        'appsumo_tier'    => 'integer',
        'granted_credits' => 'integer',
        'last_payload'    => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
