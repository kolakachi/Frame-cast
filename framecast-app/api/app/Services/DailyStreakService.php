<?php

namespace App\Services;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily Streak — the gamified retention play. Users come back once a day,
 * claim a credit bonus, and watch their streak grow. Miss a day and the
 * streak resets to Day 1. The prize ladder is conservative on purpose; we
 * can sweeten it later from telemetry.
 *
 * Stored on workspaces (not users) so multi-seat agency accounts share one
 * streak and one bonus per day. First member to claim wins for the workspace.
 */
class DailyStreakService
{
    /**
     * Prize ladder per streak day. After day 7 the cycle resets to day 1.
     * Keep totals modest until upstream_cost_usd telemetry lands and we can
     * tune from real margins: ~110cr per full week ≈ $0.55 retail.
     */
    public const PRIZE_LADDER = [
        1 => 5,
        2 => 10,
        3 => 15,
        4 => 25,
        5 => 40,
        6 => 70,
        7 => 150,
    ];

    public const STREAK_LENGTH = 7;

    public function __construct(private CreditService $credits)
    {
    }

    /**
     * Public state for the dashboard chip + modal. Tells the UI:
     *   - current_day: what day the user is on (1-7), or 1 if their streak
     *     was broken / they've never claimed
     *   - can_claim: are they eligible to claim right now?
     *   - claimed_today: did they already claim today's bonus?
     *   - prize_ladder: full schedule so the calendar UI can render
     *   - hours_until_next: when "today's" claim window ends (server day)
     */
    public function state(Workspace $workspace): array
    {
        $tz = config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($tz);
        $last = $workspace->daily_streak_last_claim_at
            ? CarbonImmutable::parse($workspace->daily_streak_last_claim_at)->setTimezone($tz)
            : null;

        // Classify the gap between last claim and today.
        $effectiveDay = $this->effectiveDayFromLast($last, $now, (int) $workspace->daily_streak_count);
        $claimedToday = $last && $last->isSameDay($now);
        $tomorrow = $now->addDay()->startOfDay();

        return [
            'current_day'       => $effectiveDay,
            'streak_count'      => (int) $workspace->daily_streak_count,
            'claimed_today'     => $claimedToday,
            'can_claim'         => ! $claimedToday,
            'prize_ladder'      => self::PRIZE_LADDER,
            'today_prize'       => self::PRIZE_LADDER[$effectiveDay] ?? 0,
            'next_claim_at'     => $tomorrow->toIso8601String(),
            'streak_length'     => self::STREAK_LENGTH,
        ];
    }

    /**
     * Claim today's bonus. Atomic — wraps the state read + credit grant in
     * a transaction with row-level lock so two parallel claims (e.g. user
     * has the tab open twice) can't double-credit.
     *
     * @return array{granted:int,new_day:int,already_claimed:bool}
     */
    public function claim(Workspace $workspace): array
    {
        return DB::transaction(function () use ($workspace) {
            $fresh = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->first();
            $tz = config('app.timezone', 'UTC');
            $now = CarbonImmutable::now($tz);
            $last = $fresh->daily_streak_last_claim_at
                ? CarbonImmutable::parse($fresh->daily_streak_last_claim_at)->setTimezone($tz)
                : null;

            if ($last && $last->isSameDay($now)) {
                return [
                    'granted'          => 0,
                    'new_day'          => (int) $fresh->daily_streak_count,
                    'already_claimed'  => true,
                ];
            }

            $newDay = $this->effectiveDayFromLast($last, $now, (int) $fresh->daily_streak_count);
            $prize = self::PRIZE_LADDER[$newDay] ?? 0;

            $fresh->forceFill([
                'daily_streak_count'         => $newDay,
                'daily_streak_last_claim_at' => $now,
            ])->save();

            if ($prize > 0) {
                $this->credits->grant(
                    (int) $fresh->getKey(),
                    $prize,
                    "daily_streak:day_{$newDay}",
                );
            }

            return [
                'granted'          => $prize,
                'new_day'          => $newDay,
                'already_claimed'  => false,
            ];
        });
    }

    /**
     * Figure out which day this claim falls on:
     *   - Never claimed before        → Day 1
     *   - Claimed yesterday           → previous day + 1 (capped to STREAK_LENGTH; wraps)
     *   - Claimed earlier today       → return current day (caller handles already_claimed)
     *   - Gap > 1 day                 → Day 1 (streak broken)
     */
    private function effectiveDayFromLast(?CarbonImmutable $last, CarbonImmutable $now, int $currentDay): int
    {
        if (! $last) {
            return 1;
        }
        // Same-day → already claimed; just echo the stored day.
        if ($last->isSameDay($now)) {
            return max(1, $currentDay);
        }
        $gap = (int) $last->startOfDay()->diffInDays($now->startOfDay());
        if ($gap > 1) {
            return 1; // streak broken
        }
        // Exactly one day gap → advance, wrap after STREAK_LENGTH.
        $next = $currentDay + 1;
        return $next > self::STREAK_LENGTH ? 1 : $next;
    }
}
