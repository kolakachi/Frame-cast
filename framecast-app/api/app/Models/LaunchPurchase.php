<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Launchpad product or bump a workspace owns (LaunchpadService). */
class LaunchPurchase extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payload'         => 'array',
        'amount'          => 'decimal:2',
        'credits_granted' => 'integer',
        'credits_revoked' => 'integer',
        'revoked_at'      => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
