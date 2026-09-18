<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pass through the My Footage flow: a source video, what we read off it,
 * the user's corrections, and the plan for their version. The read and the
 * corrections are kept apart so a re-read never silently discards what the
 * user already fixed.
 */
class FootageSession extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id', 'source_asset_id', 'brief', 'rights',
        'status', 'selection_json', 'read_json', 'corrections_json',
        'plan_json', 'consent_json', 'run_id',
    ];

    protected function casts(): array
    {
        return [
            'selection_json' => 'array',
            'read_json' => 'array',
            'corrections_json' => 'array',
            'plan_json' => 'array',
            'consent_json' => 'array',
        ];
    }

    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'source_asset_id');
    }

    /** The read with the user's corrections laid over it — what planning sees. */
    public function correctedRead(): array
    {
        $read = $this->read_json ?? [];
        $fixes = $this->corrections_json ?? [];
        $passages = [];
        foreach ((array) ($read['passages'] ?? []) as $p) {
            $fix = (array) data_get($fixes, 'passages.'.$p['id'], []);
            if (! empty($fix['drop'])) {
                $p['dropped'] = true;
            }
            foreach (['transcript', 'title', 'note'] as $field) {
                if (isset($fix[$field]) && trim((string) $fix[$field]) !== '') {
                    $p[$field] = trim((string) $fix[$field]);
                    $p['kind'] = 'observed'; // the user said so; it is no longer a guess
                }
            }
            $p['important'] = (bool) ($fix['important'] ?? false);
            $passages[] = $p;
        }
        $speakers = [];
        foreach ((array) ($read['speakers'] ?? []) as $sp) {
            $fix = (array) data_get($fixes, 'speakers.'.$sp['id'], []);
            if (! empty($fix['label'])) {
                $sp['label'] = mb_substr(trim((string) $fix['label']), 0, 60);
            }
            $speakers[] = $sp;
        }

        return ['duration' => $read['duration'] ?? 0, 'speakers' => $speakers, 'passages' => $passages];
    }
}
