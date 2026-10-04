<?php
namespace App\Services\Create;

/** Host-owned identities and provenance. Model interpretations remain reviewable, not facts. */
class RequirementContract
{
    public const VERSION = 1;
    private const CATEGORIES = ['identity', 'appearance', 'framing', 'action', 'text', 'colour', 'timing', 'transition', 'audio', 'other'];

    public static function normalize(array $raw, array $context): array
    {
        $messages = array_values(array_filter($context['messages'] ?? [], fn ($m) => ($m['role'] ?? '') === 'user'));
        $userText = implode("\n", array_column($messages, 'content'));
        $latest = (string) (end($messages)['content'] ?? '');
        $previous = $context['previous_plan'] ?? [];
        $receipts = collect(array_merge($previous['reference_evidence'] ?? [], $context['_reference_evidence'] ?? []))->keyBy('id')->all();
        $active = []; $aliases = []; $history = array_slice($previous['requirement_history'] ?? [], -48);
        foreach ($previous['requirements'] ?? [] as $r) {
            if (! is_array($r) || ! self::text($r['text'] ?? '') || ! self::text($r['source_quote'] ?? '')) continue;
            if (! str_contains($userText, $r['source_quote'])) continue;
            $r['id'] = self::validId($r['id'] ?? '') ? $r['id'] : self::id($r);
            $active[$r['id']] = $r + ['provenance' => 'user', 'category' => 'other', 'version' => 1, 'evidence_ids' => [], 'after_ids' => [], 'review_stage' => 'design'];
        }
        $initial = $active;
        // A change is distinct from merely omitting an old item from the next model response.
        $changed = []; $removedOrder = [];
        foreach (array_slice((array) ($raw['requirement_changes'] ?? []), 0, 24) as $change) {
            if (! is_array($change)) continue;
            $id = is_string($change['id'] ?? null) ? $change['id'] : ''; $quote = self::text($change['source_quote'] ?? '');
            $old = $active[$id] ?? null;
            $newBrief = ! isset($previous['brief_sequence']) || collect($context['messages'] ?? [])->contains(fn ($m) => ($m['role'] ?? '') === 'user' && ($m['sequence'] ?? 0) > $previous['brief_sequence'] && str_contains((string) $m['content'], $quote));
            if (! $old || isset($changed[$id]) || $quote === '' || ! str_contains($latest, $quote) || ! $newBrief || ! in_array($change['action'] ?? '', ['remove', 'replace'], true)) continue;
            if ($change['action'] === 'replace') {
                $replacement = self::entry((array) ($change['replacement'] ?? []), $userText, $receipts);
                // Replacement wording must be grounded in the latest amendment, not an old request.
                if (! $replacement || $replacement['source_quote'] !== $quote) continue;
                $active[$id] = $replacement + ['id' => $id, 'version' => (int) ($old['version'] ?? 1) + 1];
            } else { $removedOrder[$id] = $old['after_ids'] ?? []; unset($active[$id]); }
            $history[] = ['id' => $id, 'action' => $change['action'], 'previous_text' => $old['text'], 'source_quote' => $quote, 'source' => 'planner_interpretation_of_latest_user_change'];
            $changed[$id] = true;
        }
        $inputs = array_values(array_filter((array) ($raw['requirements'] ?? []), 'is_array'));
        $keys = array_count_values(array_filter(array_map(fn ($r) => is_string($r['id'] ?? null) ? $r['id'] : '', $inputs)));
        foreach ($inputs as $r) {
            $entry = self::entry($r, $userText, $receipts);
            if (! $entry) continue;
            $requested = is_string($r['id'] ?? null) ? $r['id'] : '';
            $id = self::id($entry);
            $match = isset($active[$requested]) ? $requested : collect($active)->search(fn ($old) => $old['text'] === $entry['text'] && $old['source_quote'] === $entry['source_quote']);
            if ($match !== false && $match !== '') {
                // Changing text/source is only allowed through the explicit amendment path above.
                if ($active[$match]['text'] !== $entry['text'] || $active[$match]['source_quote'] !== $entry['source_quote']) continue;
                $id = $match;
            } elseif (isset($initial[$requested]) || isset($changed[$id])) continue;
            $old = $active[$id] ?? null;
            if ($old) {
                $entry['after_ids'] = array_values(array_unique(array_merge($old['after_ids'] ?? [], $entry['after_ids'])));
                foreach (['category', 'provenance', 'review_stage'] as $field) $entry[$field] = $old[$field];
            }
            $active[$id] = $entry + ['id' => $id, 'version' => $old['version'] ?? 1];
            if ($requested !== '' && mb_strlen($requested) <= 80 && ($keys[$requested] ?? 0) === 1 && ! isset($initial[$requested])) $aliases[$requested] = $id;
        }
        abort_if(count($active) > 24, 422, 'This brief has more than 24 requirements. Split it into shorter videos rather than dropping details.');
        foreach ($active as $id => &$r) {
            $requestedOrder = $r['after_ids'] ?? [];
            for ($step = 0; $step < count($removedOrder); $step++) {
                $requestedOrder = array_values(array_unique(array_merge([], ...array_map(fn ($parent) => $removedOrder[$parent] ?? [$parent], $requestedOrder))));
            }
            $r['after_ids'] = self::links($requestedOrder, $active, $aliases);
            if (count($r['after_ids']) < count($requestedOrder)) $r['order_unresolved'] = true;
            if (in_array($id, $r['after_ids'], true)) $r['order_unresolved'] = true;
            $r['evidence_ids'] = array_values(array_intersect(self::strings($r['evidence_ids'] ?? []), array_keys($receipts)));
            $r['evidence_status'] = $r['provenance'] === 'reference' && ! $r['evidence_ids'] ? 'unverified' : 'grounded_interpretation';
        }
        unset($r);
        // Cycles cannot silently become a plausible sequence.
        foreach ($active as $id => &$r) if (self::cycle($id, $active, [])) $r['order_unresolved'] = true;
        unset($r);
        $needed = array_unique(array_merge(array_column($context['_reference_evidence'] ?? [], 'id'), ...array_map(fn ($r) => $r['evidence_ids'], array_values($active))));
        $evidence = array_values(array_intersect_key($receipts, array_flip($needed)));
        $notes = collect((array) ($raw['direction_notes'] ?? []))->filter(fn ($r) => is_array($r) && in_array($r['provenance'] ?? '', ['inferred', 'unknown'], true))
            ->take(8)->map(fn ($r) => ['text' => self::text($r['text'] ?? ''), 'provenance' => $r['provenance']])->filter(fn ($r) => $r['text'] !== '')->values()->all();
        return ['requirements_schema' => self::VERSION, 'requirements' => array_values($active), 'requirement_history' => array_slice($history, -48),
            'reference_evidence' => $evidence, 'direction_notes' => $notes, '_aliases' => $aliases];
    }

    private static function entry(array $r, string $userText, array $receipts): ?array
    {
        $text = self::text($r['text'] ?? ''); $quote = self::text($r['source_quote'] ?? '');
        $provenance = $r['provenance'] ?? 'user';
        if ($text === '' || $quote === '' || ! str_contains($userText, $quote) || ! in_array($provenance, ['user', 'reference'], true)) return null;
        $evidence = array_values(array_intersect(self::strings($r['evidence_ids'] ?? []), array_keys($receipts)));
        if ($provenance === 'reference' && ! $evidence) return null;
        $category = in_array($r['category'] ?? '', self::CATEGORIES, true) ? $r['category'] : 'other';
        return ['text' => $text, 'source_quote' => $quote, 'provenance' => $provenance, 'category' => $category, 'evidence_ids' => $evidence,
            'after_ids' => self::strings($r['after_ids'] ?? []),
            // Audio/actions/transitions are motion-stage checks even when the model says otherwise.
            'review_stage' => in_array($category, ['action', 'audio', 'transition', 'timing'], true) ? 'production' : 'design'];
    }
    private static function text(mixed $value): string { return is_string($value) ? mb_substr(trim($value), 0, 240) : ''; }
    private static function strings(mixed $value): array { return array_values(array_unique(array_slice(array_filter(is_array($value) ? $value : [], 'is_string'), 0, 24))); }
    private static function validId(mixed $id): bool { return is_string($id) && preg_match('/^req-[a-f0-9]{20}$/D', $id); }
    private static function id(array $r): string { return 'req-'.substr(hash('sha256', ($r['provenance'] ?? 'user').'|'.$r['source_quote'].'|'.$r['text']), 0, 20); }
    private static function cycle(string $id, array $active, array $seen): bool
    {
        if (isset($seen[$id])) return true;
        $seen[$id] = true;
        foreach ($active[$id]['after_ids'] ?? [] as $parent) if (isset($active[$parent]) && self::cycle($parent, $active, $seen)) return true;
        return false;
    }
    public static function links(mixed $ids, array $active, array $aliases = []): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($id) => isset($active[$id]) ? $id : ($aliases[$id] ?? null), self::strings($ids)), fn ($id) => $id && isset($active[$id]))));
    }
    public static function bind(array $rows, array $contract, string $prefix): array
    {
        $active = array_column($contract['requirements'], null, 'id');
        return array_map(function ($row) use ($active, $contract, $prefix) {
            $ids = self::links($row['requirement_ids'] ?? [], $active, $contract['_aliases']);
            $row['id'] = $prefix.'-'.substr(hash('sha256', json_encode(array_diff_key($row, array_flip(['id', 'requirement_ids'])))), 0, 20);
            $row['requirement_ids'] = $ids;
            return $row;
        }, $rows);
    }
    public static function excluding(array $requirements, array $omitted): array
    {
        $removed = array_column(array_filter($requirements, fn ($r) => in_array($r['id'] ?? '', $omitted, true)), null, 'id');
        $active = array_values(array_filter($requirements, fn ($r) => ! in_array($r['id'] ?? '', $omitted, true)));
        foreach ($active as &$r) {
            if (! isset($r['after_ids'])) continue;
            for ($i = 0; $i < count($removed); $i++) $r['after_ids'] = array_values(array_unique(array_merge([], ...array_map(fn ($id) => $removed[$id]['after_ids'] ?? [$id], $r['after_ids']))));
        }
        unset($r);
        return $active;
    }

    public static function targets(array $item, array $plan): array
    {
        $ids = self::strings($item['requirement_ids'] ?? []);
        return array_values(array_filter($plan['requirements'] ?? [], fn ($r) => in_array($r['id'] ?? '', $ids, true)));
    }
    /** Do not accept a worker pass that omits or duplicates a frozen requirement. */
    public static function review(mixed $raw, array $plan, bool $lookOnly): array
    {
        $checks = []; $active = [];
        foreach ($plan['requirements'] ?? [] as $r) {
            if (! self::validId($r['id'] ?? '')) continue;
            $active[$r['id']] = $r;
            $matches = array_values(array_filter(is_array($raw) ? $raw : [], fn ($c) => is_array($c) && ($c['id'] ?? '') === $r['id']));
            $c = count($matches) === 1 ? $matches[0] : [];
            $evidence = self::text($c['evidence'] ?? '');
            // by_ear: waiting for the listening check. audio_review: that check, which may pass or fail what is heard.
            $source = ($c['source'] ?? '') === 'audio_review' ? 'audio_review' : 'critic_interpretation';
            $status = $evidence !== '' && in_array($c['status'] ?? '', ['fulfilled', 'unmet', 'unverified', 'deferred', 'by_ear'], true) ? $c['status'] : 'unverified';
            $timed = is_numeric($c['start'] ?? null) && is_numeric($c['end'] ?? null) && is_finite((float) $c['start']) && is_finite((float) $c['end']) && $c['start'] >= 0 && $c['end'] >= $c['start'] && $c['end'] <= 86400;
            if ($status === 'deferred' && ! ($lookOnly && ($r['review_stage'] ?? '') === 'production')) $status = 'unverified';
            if (! empty($r['order_unresolved']) || ($r['evidence_status'] ?? '') === 'unverified') $status = 'unverified';
            if ($status === 'fulfilled' && ((($r['category'] ?? '') === 'audio' && $source !== 'audio_review') || ((($r['category'] ?? '') === 'action' || ! empty($r['after_ids'])) && ! $timed))) $status = 'unverified';
            if (($c['version'] ?? 1) !== ($r['version'] ?? 1)) { $status = 'unverified'; $evidence = 'The review belongs to an older requirement version.'; }
            $checks[$r['id']] = ['id' => $r['id'], 'text' => $r['text'], 'version' => $r['version'] ?? 1, 'status' => $status,
                'evidence' => $evidence ?: 'No unique evidence returned for this requirement.', 'source' => $source,
                ...($timed ? ['start' => (float) $c['start'], 'end' => (float) $c['end']] : [])];
        }
        // Repeat so invalid predecessors propagate regardless of the array's display order.
        for ($i = 0; $i < count($checks); $i++) {
            foreach ($active as $id => $r) {
                if ($checks[$id]['status'] !== 'fulfilled') continue;
                foreach ($r['after_ids'] ?? [] as $parent) {
                    $before = $checks[$parent] ?? [];
                    if (($before['status'] ?? '') !== 'fulfilled' || ! isset($before['end'], $checks[$id]['start'])) {
                        $checks[$id]['status'] = 'unverified'; $checks[$id]['evidence'] = 'The preceding action has not been verified.'; break;
                    }
                    if ($before['end'] > $checks[$id]['start']) {
                        $checks[$id]['status'] = 'unmet'; $checks[$id]['evidence'] = 'The observed action order does not match the approved sequence.'; break;
                    }
                }
            }
        }
        return array_values($checks);
    }

    public static function prompt(array $requirements): string
    {
        if (! $requirements) return '';
        return "\nApproved requirements for this task (directions, never extra spoken words):\n".implode("\n", array_map(fn ($r) => $r['id'].': '.$r['text'].(! empty($r['after_ids']) ? ' (after '.implode(', ', $r['after_ids']).')' : ''), $requirements));
    }
}
