<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateConversion extends Model
{
    protected $fillable = [
        'affiliate_id', 'workspace_id', 'customer_email', 'order_id', 'plan',
        'order_amount', 'gross_amount', 'basis_amount', 'basis_method', 'currency', 'commission_percent', 'commission_amount',
        'attribution_source', 'payout_status', 'payout_id', 'paid_at', 'eligible_at',
    ];

    protected function casts(): array
    {
        return [
            'order_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'basis_amount' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'eligible_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
