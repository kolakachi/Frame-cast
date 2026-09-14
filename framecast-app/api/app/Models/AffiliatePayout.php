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
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_amount' => 'decimal:2',
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
     * bank transfer identifies the run without a lookup. Uniqueness is still
     * enforced by the column; this only has to be readable.
     */
    public static function nextReference(Affiliate $affiliate): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($affiliate->code, 0, 8)) ?: 'AFF');
        $year = now()->year;
        // Counts voided runs too: a reference is never handed out twice, even
        // when the payment it named was cancelled.
        $n = static::query()->where('affiliate_id', $affiliate->getKey())
            ->whereYear('created_at', $year)->count() + 1;

        return sprintf('%s-%d-%03d', $prefix, $year, $n);
    }
}
