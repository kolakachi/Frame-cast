<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where an affiliate's money is sent.
 *
 * The account number is encrypted and hidden. Everything an operator or the
 * affiliate needs day to day — the bank, the name on the account, the last
 * four digits — is readable without decrypting it, so the full number is only
 * ever touched when a transfer is actually being made.
 */
class AffiliatePaymentDetail extends Model
{
    protected $fillable = [
        'affiliate_id', 'account_name', 'bank_name', 'account_number',
        'account_number_last4', 'bank_code', 'country', 'payout_currency',
        'status', 'verified_at', 'verified_by_user_id', 'rejected_reason', 'submitted_at',
    ];

    /** Never serialised. The admin endpoint asks for it explicitly. */
    protected $hidden = ['account_number'];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'verified_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** What the owner is shown back: enough to recognise, not enough to misuse. */
    public function masked(): string
    {
        return $this->account_number_last4
            ? '••••'.$this->account_number_last4
            : '••••';
    }

    /**
     * Any edit returns the row to unverified.
     *
     * Verification checked specific digits; a new account number has not been
     * checked, and carrying the old approval forward would let someone redirect
     * a verified payout to an account nobody looked at.
     */
    public function applySubmission(array $values): void
    {
        $changedAccount = isset($values['account_number'])
            && $values['account_number'] !== $this->account_number;

        $this->fill($values);

        if ($changedAccount || $this->isDirty(['bank_name', 'bank_code', 'account_name', 'country'])) {
            $this->status = 'unverified';
            $this->verified_at = null;
            $this->verified_by_user_id = null;
            $this->rejected_reason = null;
        }

        $this->submitted_at = now();
        $this->save();
    }
}
