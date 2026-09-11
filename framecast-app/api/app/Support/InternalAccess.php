<?php

namespace App\Support;

use App\Models\User;
use App\Services\WorkspaceUsageService;

/**
 * Who can see features that are still internal-only.
 *
 * Two doors: a platform admin role, or an @wyvstudio.com mailbox. Kept in one
 * place so a feature is never gated by a hand-rolled email check that drifts
 * from this one.
 *
 * The domain test compares the host exactly, never a substring. At least one
 * paying customer has the company name in the local part of an address on an
 * unrelated domain, so `str_contains($email, 'wyvstudio')` would hand them an
 * unreleased feature.
 */
final class InternalAccess
{
    private const DOMAIN = 'wyvstudio.com';

    public static function allows(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (WorkspaceUsageService::isAdmin($user)) {
            return true;
        }

        return self::hasInternalDomain((string) $user->email);
    }

    /**
     * True when the address sits on the company domain. Compares the part
     * after the LAST "@" so an address like "wyvstudio.com@evil.test" — whose
     * host is evil.test — cannot pass.
     */
    public static function hasInternalDomain(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }

        $host = strtolower(trim(substr($email, $at + 1)));

        return $host === self::DOMAIN;
    }
}
