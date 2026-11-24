<?php

namespace App\Services\Launch;

/**
 * Reads a JVZoo IPN (JVZIPN; owner, 2026-11-24: useofferiq.com sells the OfferIQ funnel on JVZoo too). JVZoo POSTs
 * one form-encoded event per transaction to the product's JVZIPN URL:
 *   ccustname, ccustemail, cproditem (product id), cprodtitle, ctransaction (SALE | BILL | RFND | CGBK | INSF |
 *   CANCEL-REBILL | UNCANCEL-REBILL), ctransreceipt, ctransamount, cupsellreceipt, …, cverify.
 *
 * cverify is JVZoo's signature: every other field's value in field-name order, each followed by "|", then the secret
 * key; SHA-1, upper-cased, first 8 characters. Each upsell is its own transaction with its own receipt, and a refund
 * names the receipt it refunds. Every product here is one-time, so a rebill grants nothing.
 */
final class JvzooIpn
{
    /** JVZoo product id → our product (the same OfferIQ products as Launchpad's offer 622, WyvStudio side included). */
    public const PRODUCT_IDS = [
        463835 => 'oiq_fe',
        463839 => 'oiq_bundle',
        464187 => 'oiq_scale',
        464191 => 'oiq_pro',
        464205 => 'oiq_dfy',
        464211 => 'oiq_agency',
        // The same products at a discount, sold from each page's exit popup: granted exactly the same.
        464185 => 'oiq_bundle',
        464189 => 'oiq_scale',
        464199 => 'oiq_pro',
        464209 => 'oiq_dfy',
        464215 => 'oiq_agency',
    ];

    private const REVOKE_ACTIONS = ['RFND' => 'refunded', 'CGBK' => 'chargeback'];

    /** @param  array<string, mixed>  $fields  the form fields exactly as JVZoo sent them */
    public function __construct(public readonly array $fields)
    {
    }

    /** Parse the raw body, so no middleware (trimming, empty-to-null) changes a value the signature covers. */
    public static function fromBody(string $body): self
    {
        parse_str($body, $fields);

        return new self($fields);
    }

    public function verified(string $secret): bool
    {
        $given = strtoupper(trim((string) ($this->fields['cverify'] ?? '')));
        if ($secret === '' || $given === '') {
            return false;
        }
        $keys = array_keys(array_diff_key($this->fields, ['cverify' => true]));
        sort($keys);
        $pop = '';
        foreach ($keys as $key) {
            $pop .= (is_scalar($this->fields[$key]) ? (string) $this->fields[$key] : '').'|';
        }
        $pop .= $secret;
        if (! mb_check_encoding($pop, 'UTF-8')) {
            $pop = mb_convert_encoding($pop, 'UTF-8', 'ISO-8859-1');
        }

        return hash_equals(strtoupper(substr(sha1($pop), 0, 8)), $given);
    }

    public function action(): string
    {
        return strtoupper(trim((string) ($this->fields['ctransaction'] ?? '')));
    }

    public function isSale(): bool
    {
        return $this->action() === 'SALE';
    }

    /** 'refunded' or 'chargeback' for an event that takes the product back, else null. */
    public function revokeStatus(): ?string
    {
        return self::REVOKE_ACTIONS[$this->action()] ?? null;
    }

    public function productId(): ?int
    {
        $id = $this->fields['cproditem'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function product(): ?string
    {
        return self::PRODUCT_IDS[$this->productId()] ?? null;
    }

    /** Prefixed, so a JVZoo receipt can never collide with a Launchpad transaction id. */
    public function receipt(): ?string
    {
        $receipt = trim((string) ($this->fields['ctransreceipt'] ?? ''));

        return $receipt !== '' ? 'jvz:'.$receipt : null;
    }

    public function email(): string
    {
        return (string) ($this->fields['ccustemail'] ?? '');
    }

    public function name(): ?string
    {
        $name = trim((string) ($this->fields['ccustname'] ?? ''));

        return $name !== '' ? $name : null;
    }

    public function amount(): ?float
    {
        $amount = $this->fields['ctransamount'] ?? null;

        return is_numeric($amount) ? (float) $amount : null;
    }
}
