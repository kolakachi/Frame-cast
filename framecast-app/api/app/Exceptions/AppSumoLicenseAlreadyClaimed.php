<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A licence that is already provisioned onto a workspace, being activated
 * again from a different email address.
 *
 * Carries a masked form of the address that holds it so the buyer can be told
 * where their deal actually lives. They usually do not remember signing up
 * with the other one — that is precisely how they got here.
 */
class AppSumoLicenseAlreadyClaimed extends RuntimeException
{
    public function __construct(public readonly string $maskedEmail)
    {
        parent::__construct('This AppSumo licence is already active on another account.');
    }

    /** bob37013@gmail.com -> b*******3@gmail.com */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = match (true) {
            mb_strlen($local) <= 2 => mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1)),
            default => mb_substr($local, 0, 1).str_repeat('*', mb_strlen($local) - 2).mb_substr($local, -1),
        };

        return $domain === '' ? $visible : $visible.'@'.$domain;
    }
}
