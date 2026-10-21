<?php

namespace App\Services\Launch;

/**
 * Reads a Launchpad IPN (owner, 2026-10-21). Launchpad POSTs one JSON object per event to the URL set in
 * Offer → Integrations, with no signature (the URL carries our token instead):
 *   {"product_id":5945,"transaction_id":"pi_…","total_amount":24,"action":"SALE","product":{"name":"…"},
 *    "user":{"name":"…","email":"…"},"id":205,…}
 *
 * The product ids are Launchpad's (owner's list, 2026-10-21); both the product id and the offer-product id from the
 * checkout links are accepted. How a bump arrives is not documented yet, so products() guesses it in one place:
 * a known bump name, or a sale whose total covers the main product plus its bump. Fix it here once a real bump
 * payload has been seen in the admin billing-webhooks view.
 */
final class LaunchpadIpn
{
    /** Launchpad product id (and offer-product id) → our main product. */
    public const PRODUCT_IDS = [
        5945 => 'fe',     6942 => 'fe',
        5946 => 'bundle', 6944 => 'bundle',
        5947 => 'pro',    6945 => 'pro',
        5949 => 'agency', 6947 => 'agency',
    ];

    /** Sold in the same funnel but nothing to grant here: OfferIQ is the marketer's product; 5980 is the thank-you page. */
    public const IGNORED_IDS = [5948, 6946, 5980];

    private const GRANT_ACTIONS = ['SALE', 'PURCHASE', 'COMPLETED'];

    /** Actions that take a product back; a chargeback or dispute is recorded as one. */
    private const REVOKE_ACTIONS = ['REFUND' => 'refunded', 'REFUNDED' => 'refunded', 'CANCEL' => 'refunded', 'CANCELLED' => 'refunded',
        'CANCELED' => 'refunded', 'REVERSAL' => 'refunded', 'CHARGEBACK' => 'chargeback', 'DISPUTE' => 'chargeback'];

    public function __construct(public readonly array $payload)
    {
    }

    public function action(): string
    {
        return strtoupper(trim((string) ($this->payload['action'] ?? '')));
    }

    public function isSale(): bool
    {
        return in_array($this->action(), self::GRANT_ACTIONS, true);
    }

    /** 'refunded' or 'chargeback' for an action that takes the product back, else null. Never for a SALE. */
    public function revokeStatus(): ?string
    {
        return self::REVOKE_ACTIONS[$this->action()] ?? null;
    }

    public function productId(): ?int
    {
        $id = $this->payload['product_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function isIgnoredProduct(): bool
    {
        return in_array($this->productId(), self::IGNORED_IDS, true);
    }

    public function receipt(): ?string
    {
        $tx = trim((string) ($this->payload['transaction_id'] ?? ''));
        if ($tx !== '') return $tx;
        $id = $this->payload['id'] ?? null;

        return $id !== null && $id !== '' ? 'lp:'.$id : null;
    }

    public function email(): string
    {
        return (string) ($this->payload['user']['email'] ?? $this->payload['email'] ?? '');
    }

    public function name(): ?string
    {
        $name = $this->payload['user']['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    public function amount(): ?float
    {
        $amount = $this->payload['total_amount'] ?? null;

        return is_numeric($amount) ? (float) $amount : null;
    }

    /**
     * Our products this event is about: the main product, plus its bump when the total covers both; or a bump on its
     * own when Launchpad sends it as a product of its own under a name that says which. Empty when unrecognised.
     *
     * @return list<string>
     */
    public function products(): array
    {
        $main = self::PRODUCT_IDS[$this->productId()] ?? null;
        if ($main === null) {
            $bump = self::bumpByName((string) ($this->payload['product']['name'] ?? ''));

            return $bump ? [$bump] : [];
        }
        $out = [$main];
        $bump = LaunchCatalogue::bumpOf($main);
        $amount = $this->amount();
        if ($bump && $amount !== null
            && $amount >= LaunchCatalogue::PRODUCTS[$main]['price'] + LaunchCatalogue::PRODUCTS[$bump]['price'] - 0.01) {
            $out[] = $bump;
        }

        return $out;
    }

    /** A bump sold as its own product, recognised by what its name says it gives. */
    public static function bumpByName(string $name): ?string
    {
        $n = strtolower($name);

        return match (true) {
            str_contains($n, 'template')                                   => 'bundle_dfy',
            str_contains($n, 'character') || str_contains($n, 'voice')
                || str_contains($n, 'slot')                                => 'pro_seats',
            (bool) preg_match('/2,?000/', $n)                              => 'agency_credits',
            (bool) preg_match('/\b600\b/', $n)                             => 'fe_credits',
            default                                                        => null,
        };
    }
}
