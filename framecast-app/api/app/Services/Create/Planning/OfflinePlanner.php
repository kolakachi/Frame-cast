<?php
namespace App\Services\Create\Planning;

use Illuminate\Support\Str;

/**
 * Deterministic planner for fixture mode and tests. It reads the brief and
 * files literally; it is not a creative model and says nothing it cannot
 * derive from them.
 */
class OfflinePlanner implements Planner
{
    public function plan(array $c): array
    {
        $briefs = collect($c['messages'])->where('role', 'user')->pluck('content');
        $brief = (string) $briefs->last();
        $sources = collect($c['files'])->where('purpose', 'source')->values();
        $image = ($c['settings']['output_kind'] ?? 'video') === 'image';
        $duration = (int) ($c['settings']['duration_seconds'] ?? 15);
        // Copy the user quoted in any brief of this conversation, latest last.
        preg_match_all('/["“]([^"”]{3,80})["”]/u', $briefs->implode("\n"), $quoted);
        $callouts = array_slice(array_values(array_unique(array_map('trim', $quoted[1]))), 0, 5);
        // The user's edited copy from the previous plan wins over re-reading the briefs.
        if (! empty($c['previous_plan']['approved_copy'])) $callouts = array_slice($c['previous_plan']['approved_copy'], 0, 6);
        if (! $callouts) $callouts = array_slice($c['approved_facts'] ?? [], 0, 3);
        $third = max(1, intdiv($duration, 3));
        $plan = [
            'summary' => $image
                ? 'I will make one image from your brief'.($sources->count() ? ' using '.$sources->pluck('title')->join(', ', ' and ') : '').'. Nothing else is generated.'
                : 'I will build a '.$duration.'-second video'.($sources->count() ? ' from '.$sources->pluck('title')->join(', ', ' and ') : ' with kinetic text').', with '.($callouts ? count($callouts).' callouts' : 'your message').' and a clear ending.',
            'reused' => $sources->map(fn ($f) => ['asset_id' => $f['asset_id'], 'use' => $f['asset_type'] === 'video' ? 'Opening footage, kept as recorded' : 'Shown as supplied'])->all(),
            'scenes' => $image ? [] : [
                ['label' => 'Hook', 'start' => 0, 'end' => $third, 'idea' => 'Open on the strongest line'],
                ['label' => 'Body', 'start' => $third, 'end' => $duration - $third, 'idea' => 'One callout at a time'],
                ['label' => 'Ending', 'start' => $duration - $third, 'end' => $duration, 'idea' => 'Offer or call to action'],
            ],
            'callouts' => $callouts,
            'decisions' => $image ? [] : [[
                'id' => 'opening', 'question' => 'How should it open?',
                'options' => [
                    ['id' => 'type_on', 'label' => 'Fast type-on headline', 'detail' => 'Energetic; the first words land in under a second.', 'kind' => 'included', 'tool' => null],
                    ['id' => 'reveal', 'label' => 'Slow reveal', 'detail' => 'Calmer; the product or first frame fades up.', 'kind' => 'included', 'tool' => null],
                ],
            ]],
            'kept_as_is' => array_merge($sources->map(fn ($f) => $f['title'])->all(), array_map(fn ($f) => '"'.Str::limit($f, 60).'"', array_slice($c['approved_facts'] ?? [], 0, 2))),
            'media' => $sources->contains(fn ($f) => in_array($f['asset_type'], ['video', 'audio'], true))
                ? [['kind' => 'transcript', 'description' => 'Word-timed transcript so captions and callouts land on the spoken words']] : [],
            'left_out' => $callouts ? '' : 'No exact on-screen lines were given, so I will ask before adding claims.',
        ];
        return ['plan' => $plan, 'provider' => 'offline-planner-v1', 'usage' => []];
    }
}
