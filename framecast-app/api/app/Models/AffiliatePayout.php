<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AffiliatePayout extends Model
{
    protected $fillable = [
        'affiliate_id', 'reference', 'period_start', 'period_end', 'sales_count',
        'total_amount', 'currency', 'status', 'method', 'note', 'paid_at',
        'voided_at', 'void_reason', 'created_by_user_id',
        'payout_currency', 'payout_amount', 'fx_rate', 'fx_source', 'fx_captured_at',
        'payment_reference', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_amount' => 'decimal:2',
            'payout_amount' => 'decimal:2',
            'fx_rate' => 'decimal:6',
            'fx_captured_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class, 'payout_id');
    }

    /**
     * Sequential per affiliate and per year, so a reference read aloud over a
     * bank transfer identifies the run without a lookup.
     *
     * Built from the name rather than the code. Codes are random by design —
     * they travel in public URLs and must not identify anyone — but this
     * reference goes only onto that affiliate's own statement and onto the
     * transfer we send them, where being readable is the entire point.
     */
    public static function nextReference(Affiliate $affiliate): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr((string) $affiliate->name, 0, 8))) ?: 'AFF';
        $year = now()->year;
        // Counts voided runs too: a reference is never handed out twice, even
        // when the payment it named was cancelled.
        $n = static::query()->where('affiliate_id', $affiliate->getKey())
            ->whereYear('created_at', $year)->count() + 1;

        $reference = sprintf('%s-%d-%03d', $prefix, $year, $n);

        // Two affiliates can share a name; the sequence is per affiliate, so
        // their references would otherwise collide on the unique column.
        return static::query()->where('reference', $reference)->exists()
            ? sprintf('%s%d-%d-%03d', $prefix, $affiliate->getKey(), $year, $n)
            : $reference;
    }
}
