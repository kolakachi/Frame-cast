<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Which editor a person opens: the classic one or the redesign.
 *
 * Rules live in config/editor.php. The answer is computed on the server so the
 * staff-only stage cannot be opened from the browser: a non-staff account that
 * saved "new" while it was open still gets classic if it closes again.
 */
final class EditorChoice
{
    public const CLASSIC = 'classic';

    public const NEW = 'new';

    public static function available(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return config('editor.new_open') || InternalAccess::allows($user);
    }

    /** The editor this person opens. */
    public static function for(?User $user): string
    {
        if (! self::available($user)) {
            return self::CLASSIC;
        }

        $chosen = $user->preferences_json['editor'] ?? null;
        if (in_array($chosen, [self::CLASSIC, self::NEW], true)) {
            return $chosen;
        }

        return self::isNewUser($user) ? self::NEW : self::CLASSIC;
    }

    /** Created at or after the opening deploy, so they never learned classic. */
    public static function isNewUser(User $user): bool
    {
        if (! config('editor.new_open')) {
            return false;
        }

        $from = config('editor.new_users_from');
        if (! $from || ! $user->created_at) {
            return false;
        }

        try {
            return $user->created_at->greaterThanOrEqualTo(CarbonImmutable::parse($from));
        } catch (\Throwable) {
            return false;
        }
    }
}
