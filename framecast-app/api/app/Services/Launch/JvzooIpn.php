<?php

namespace App\Services\Launch;

/**
 * Reads a JVZoo IPN (owner, 2026-11-24: useofferiq.com sells the OfferIQ funnel on JVZoo too). JVZoo POSTs one
 * form-encoded event per transaction to the product's IPN URL (Advanced Settings), in one of two shapes; both land on
 * the same endpoint and are told apart by their field names:
 *
 *   v2 (JVZIPN v2, the one we set up, 2026-11-27): paykey, transaction_id, transaction_type (SALE | BILL | RFND; a
 *       chargeback also arrives as RFND), total (dollars), date, product_id, product_name, customer_email,
 *       customer_first_name, customer_last_name, …, transactionPayouts (a JSON string), cverify.
 *       cverify = SHA-1 of "paykey|customer_email|product_name|transaction_type|date|" + secret, first 8, upper-cased.
 *   v1 (classic JVZIPN): ccustname, ccustemail, cproditem, ctransaction (SALE | BILL | RFND | CGBK | INSF | …),
 *       ctransreceipt, ctransamount, …, cverify = every other field's value in field-name order, each followed by
 *       "|", then the secret; SHA-1, upper-cased, first 8.
 *
 * The secret is the account's (My Account → Seller Settings), the same for both. Each upsell is its own transaction
 * with its own receipt, and a refund names the receipt it refunds. Every product here is one-time, so a rebill grants
 * nothing. JVZoo does not retry a failed delivery.
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

    /** The v2 field each reader takes, and its v1 name. */
    private const V1_NAMES = [
        'transaction_type' => 'ctransaction',
        'transaction_id' => 'ctransreceipt',
        'product_id' => 'cproditem',
        'customer_email' => 'ccustemail',
        'total' => 'ctransamount',
    ];

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

    public function isV2(): bool
    {
        return array_key_exists('transaction_type', $this->fields) || array_key_exists('customer_email', $this->fields);
    }

    public function verified(string $secret): bool
    {
        $given = strtoupper(trim((string) ($this->fields['cverify'] ?? '')));
        if ($secret === '' || $given === '') {
            return false;
        }

        if ($this->isV2()) {
            $pop = '';
            foreach (['paykey', 'customer_email', 'product_name', 'transaction_type', 'date'] as $key) {
                $pop .= $this->raw($key).'|';
            }
            // As JVZoo's own sample does it.
            $pop = mb_convert_encoding($pop.$secret, 'UTF-8');
        } else {
            $keys = array_keys(array_diff_key($this->fields, ['cverify' => true]));
            sort($keys);
            $pop = '';
            foreach ($keys as $key) {
                $pop .= $this->raw($key).'|';
            }
            $pop .= $secret;
            if (! mb_check_encoding($pop, 'UTF-8')) {
                $pop = mb_convert_encoding($pop, 'UTF-8', 'ISO-8859-1');
            }
        }

        return hash_equals(strtoupper(substr(sha1($pop), 0, 8)), $given);
    }

    public function action(): string
    {
        return strtoupper(trim($this->field('transaction_type')));
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
        $id = $this->field('product_id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function product(): ?string
    {
        return self::PRODUCT_IDS[$this->productId()] ?? null;
    }

    public function productTitle(): ?string
    {
        return ($this->fields['product_name'] ?? $this->fields['cprodtitle'] ?? null) ?: null;
    }

    /** Prefixed, so a JVZoo receipt can never collide with a Launchpad transaction id. The same in v1 and v2. */
    public function receipt(): ?string
    {
        $receipt = trim($this->field('transaction_id'));

        return $receipt !== '' ? 'jvz:'.$receipt : null;
    }

    public function email(): string
    {
        return $this->field('customer_email');
    }

    public function name(): ?string
    {
        $name = $this->isV2()
            ? trim($this->raw('customer_first_name').' '.$this->raw('customer_last_name'))
            : trim($this->raw('ccustname'));

        return $name !== '' ? $name : null;
    }

    public function amount(): ?float
    {
        $amount = $this->field('total');

        return is_numeric($amount) ? (float) $amount : null;
    }

    /** A field by its v2 name, read from its v1 name on a v1 IPN. */
    private function field(string $v2): string
    {
        return $this->raw($this->isV2() ? $v2 : (self::V1_NAMES[$v2] ?? $v2));
    }

    private function raw(string $key): string
    {
        $value = $this->fields[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
