<?php

namespace App\Services\CruiseControl;

use App\Models\Asset;
use App\Models\ExportJob;
use App\Models\Niche;
use App\Models\Project;
use App\Models\Scene;
use App\Services\CreditService;
use Illuminate\Support\Collection;

/**
 * What the assistant needs to know about the video beyond its scene text (2026-10-28): the niche and its playbook,
 * the platform and target length, each scene's real seconds and pace, what failed or is still running, the balance,
 * and a free list of problems worth fixing (too fast to follow, a still held too long, repeated pictures, a voice or
 * style that does not match the rest, a slow hook, missing parts). Measured from the data, no model call.
 */
final class CruiseVideoState
{
    /** @param Collection<int, Scene> $scenes */
    public function for(Project $project, Collection $scenes): array
    {
        $audio = Asset::query()->whereIn('id', $scenes->map(fn ($s) => (int) data_get($s->voice_settings_json, 'audio_asset_id'))->filter()->values())
            ->get(['id', 'duration_seconds'])->keyBy('id');
        $seconds = [];
        $timing = [];
        foreach ($scenes as $s) {
            $a = $audio->get((int) data_get($s->voice_settings_json, 'audio_asset_id'));
            $sec = (float) ($a?->duration_seconds ?: $s->duration_seconds ?: 0);
            $words = count(preg_split('/\s+/u', trim((string) $s->script_text), -1, PREG_SPLIT_NO_EMPTY));
            $seconds[$s->id] = $sec;
            $timing[$s->id] = $sec > 0 ? sprintf('secs=%.1f wps=%.1f%s', $sec, $words / max(0.1, $sec), $a ? '' : ' (planned, voice not recorded)') : null;
        }
        $total = array_sum($seconds);

        $niche = $project->niche_id ? Niche::query()->find($project->niche_id) : null;
        $target = (int) ($project->duration_target_seconds ?: 0);
        $projectLines = implode("\n", array_filter([
            '  niche: '.($niche ? $niche->name : 'none (custom)'),
            $niche ? '  NICHE PLAYBOOK: '.$niche->guidance() : null,
            '  platform: '.($project->platform_target ?: 'not set'),
            $target ? "  target_length: {$target} s (a window of about ".(int) round($target * 0.8).'-'.(int) round($target * 1.25).' s is fine)' : null,
            $total > 0 ? sprintf('  current_length: %.0f s of narration', $total) : null,
        ]));

        $state = [];
        $issues = [];
        foreach ($scenes->values() as $i => $s) {
            $n = (int) ($s->scene_order ?: $i + 1);
            $img = is_array($s->image_generation_settings_json) ? $s->image_generation_settings_json : [];
            if (! empty($img['in_progress'])) $state[] = "  scene {$n}: picture still being made";
            if (! empty($img['animation_in_progress'])) $state[] = "  scene {$n}: animation still being made";
            if (! empty($img['last_error'])) $state[] = "  scene {$n}: last picture failed (".mb_substr((string) $img['last_error'], 0, 120).')';
            if (! $s->visual_asset_id && empty($img['in_progress']) && ! in_array($s->visual_type, ['waveform', 'none'], true)) $issues[] = "  scene {$n}: has no picture";
            if (trim((string) $s->script_text) !== '' && ! data_get($s->voice_settings_json, 'audio_asset_id')) $issues[] = "  scene {$n}: no voice recorded";
            if (data_get($s->voice_settings_json, 'is_outdated')) $issues[] = "  scene {$n}: voice is out of date with its script (re-record)";
            $sec = $seconds[$s->id] ?? 0;
            $words = count(preg_split('/\s+/u', trim((string) $s->script_text), -1, PREG_SPLIT_NO_EMPTY));
            if ($sec > 0 && $words / $sec > 3.2) $issues[] = sprintf('  scene %d: too fast to follow (%.1f words a second)', $n, $words / $sec);
            if ($sec > 6.5 && in_array($s->visual_type, ['ai_image', 'stock_image', 'image'], true)) $issues[] = sprintf('  scene %d: one still held %.0f s; split it or animate it', $n, $sec);
        }
        if (($first = $scenes->first()) && ($seconds[$first->id] ?? 0) > 4.0) {
            $issues[] = sprintf('  hook: the first scene takes %.0f s; the hook should land in under 2 s', $seconds[$first->id]);
        }
        $prompts = $scenes->map(fn ($s) => mb_strtolower(trim((string) $s->visual_prompt)))->filter();
        foreach ($prompts->countBy()->filter(fn ($c) => $c > 1)->keys() as $p) {
            $ids = $scenes->filter(fn ($s) => mb_strtolower(trim((string) $s->visual_prompt)) === $p)->pluck('scene_order')->implode(', ');
            $issues[] = "  scenes {$ids}: the same picture prompt repeated";
        }
        foreach (['voice' => fn ($s) => data_get($s->voice_settings_json, 'voice_id'), 'style' => fn ($s) => $s->visual_style] as $what => $get) {
            $values = $scenes->map($get)->filter();
            if ($values->unique()->count() < 2) continue;
            $main = $values->countBy()->sortDesc()->keys()->first();
            foreach ($scenes as $s) if ($get($s) && $get($s) !== $main) $issues[] = "  scene {$s->scene_order}: {$what} \"{$get($s)}\" differs from the rest (\"{$main}\")";
        }
        if ($target && $total > 0 && ($total < $target * 0.8 || $total > $target * 1.25)) {
            $issues[] = sprintf('  length: %.0f s against the %d s target', $total, $target);
        }

        $export = ExportJob::query()->where('project_id', $project->getKey())->latest('id')->first(['status', 'failure_reason']);
        if ($export) $state[] = '  last export: '.$export->status.($export->status === 'failed' && $export->failure_reason ? ' ('.mb_substr((string) $export->failure_reason, 0, 120).')' : '');
        $state[] = '  credits balance: '.app(CreditService::class)->balance((int) $project->workspace_id);

        // The hook options already written and scored for this video, best first.
        $hooks = \App\Models\ProjectHookOption::query()->where('project_id', $project->getKey())->whereNotNull('hook_score')
            ->orderByDesc('hook_score')->limit(3)->get(['hook_text', 'hook_score']);
        if ($hooks->isNotEmpty()) {
            $projectLines .= "\n  scored hook options: ".$hooks->map(fn ($h) => '"'.$h->hook_text.'" ('.(int) $h->hook_score.'/100)')->implode('; ');
        }

        return [
            'scene_timing' => array_filter($timing),
            'project_lines' => $projectLines,
            'state_lines' => implode("\n", $state),
            'issues_lines' => $issues === [] ? '  (none found)' : implode("\n", array_slice($issues, 0, 14)),
        ];
    }
}
