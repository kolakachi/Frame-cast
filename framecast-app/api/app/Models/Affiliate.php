<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Affiliate extends Model
{
    protected $fillable = ['code', 'name', 'email', 'commission_percent', 'status', 'notes'];

    protected function casts(): array
    {
        return ['commission_percent' => 'decimal:2'];
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class);
    }

    /** Active affiliate for a code, or null. Codes are matched case-insensitively. */
    public static function findByCode(?string $code): ?self
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        return static::query()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->where('status', 'active')
            ->first();
    }
}
