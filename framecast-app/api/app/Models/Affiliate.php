<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Affiliate extends Model
{
    protected $fillable = ['code', 'name', 'email', 'commission_percent', 'status', 'notes', 'access_key', 'last_login_at'];

    /** Never serialised to a response — the admin panel asks for it explicitly. */
    protected $hidden = ['access_key'];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            // Encrypted rather than hashed: an operator has to be able to
            // re-read it to send it on, and regenerating would lock out an
            // affiliate who had merely misplaced theirs.
            'access_key' => 'encrypted',
            'last_login_at' => 'datetime',
        ];
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(AffiliatePayout::class);
    }

    public function paymentDetail(): HasOne
    {
        return $this->hasOne(AffiliatePaymentDetail::class);
    }

    /**
     * Characters that survive being read off a screen and typed into a phone.
     * No 0/o, 1/l/i — an affiliate who mistypes their own code loses the sale
     * and has no way to tell that is what happened.
     */
    private const CODE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const CODE_LENGTH = 8;

    /**
     * A code identifies a link, not a person.
     *
     * Deriving it from the name put the affiliate's identity into a string
     * that ends up in public URLs, on their own posts, and in any referrer
     * header the landing page sends onward — and it leaked the roster: anyone
     * holding one link could guess the others. Random costs nothing here,
     * because nobody has to remember it.
     */
    public static function generateCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;

        // Collisions are vanishingly unlikely at 31^8, but a duplicate would
        // silently pay the wrong person, so it is checked rather than assumed.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            if (! static::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        // Eight straight collisions means the assumption above is wrong; widen
        // rather than hand back something already in use.
        return $code.bin2hex(random_bytes(3));
    }

    /**
     * The portal secret. Longer than the referral code and drawn from the same
     * unambiguous alphabet, since it also gets read off a screen and retyped.
     */
    public static function generateAccessKey(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $key = '';
        for ($i = 0; $i < 20; $i++) {
            $key .= $alphabet[random_int(0, $max)];
        }

        // Grouped for reading aloud without losing your place.
        return implode('-', str_split($key, 5));
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
