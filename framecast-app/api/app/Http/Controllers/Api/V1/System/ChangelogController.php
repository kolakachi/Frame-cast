<?php

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Product changelog — one source (config/changelog.php), two surfaces:
 * the in-app "What's new" panel and the public marketing page.
 */
class ChangelogController extends Controller
{
    /** Authenticated view: entries plus how many are new since this user last looked. */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user    = $request->user();
        $entries = $this->entries();
        $seenAt  = $user->changelog_seen_at;

        $unread = $seenAt === null
            ? count($entries)
            : count(array_filter(
                $entries,
                // startOfDay, not endOfDay: entries carry only a date while
                // seen_at is a timestamp, and endOfDay made every entry dated
                // TODAY count as unread until midnight — the dot came back on
                // refresh all day, however many times the drawer was opened.
                // The trade-off (an entry published later the same day the
                // user last looked won't re-dot) is the lesser wrong.
                fn (array $e) => $e['date'] !== null && Carbon::parse($e['date'])->startOfDay()->greaterThan($seenAt),
            ));

        return response()->json([
            'data' => [
                'entries'      => $entries,
                'unread_count' => $unread,
                'seen_at'      => $seenAt?->toIso8601String(),
            ],
            'meta' => [],
        ]);
    }

    /** Mark the changelog as read for this user — clears the badge. */
    public function markSeen(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['changelog_seen_at' => now()])->save();

        return response()->json([
            'data' => ['seen_at' => $user->changelog_seen_at?->toIso8601String()],
            'meta' => [],
        ]);
    }

    /**
     * Unauthenticated feed for the public changelog page. Same content as the
     * in-app view minus the per-user read state — there is nothing private in
     * a release note.
     */
    public function publicIndex(): JsonResponse
    {
        return response()->json([
            'data' => ['entries' => $this->entries()],
            'meta' => [],
        ]);
    }

    /**
     * Normalised entries, newest first. Anything malformed is dropped rather
     * than surfaced half-rendered — a broken release note should never be able
     * to break the sidebar.
     *
     * @return list<array{slug:string,date:?string,tag:string,title:string,body:string}>
     */
    private function entries(): array
    {
        $raw = (array) config('changelog.entries', []);

        $entries = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $title = trim((string) ($entry['title'] ?? ''));
            $slug  = trim((string) ($entry['slug'] ?? ''));
            if ($title === '' || $slug === '') {
                continue;
            }

            $date = $entry['date'] ?? null;
            $tag  = strtolower(trim((string) ($entry['tag'] ?? 'improved')));

            $entries[] = [
                'slug'  => $slug,
                'date'  => $date ? (string) $date : null,
                'tag'   => in_array($tag, ['new', 'improved', 'fixed'], true) ? $tag : 'improved',
                'title' => $title,
                'body'  => trim((string) ($entry['body'] ?? '')),
            ];
        }

        // Config order is authoritative for same-day entries; date sorts the rest.
        usort($entries, fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']));

        return $entries;
    }
}
