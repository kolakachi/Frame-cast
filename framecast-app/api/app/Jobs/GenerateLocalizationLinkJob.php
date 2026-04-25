<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\LocalizationGroup;
use App\Models\LocalizationLink;
use App\Models\Project;
use App\Models\Scene;
use App\Models\VoiceProfile;
use App\Services\Generation\Translation\TranslationAdapter;
use App\Services\Generation\TTS\TTSAdapter;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateLocalizationLinkJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $localizationLinkId,
    ) {
        $this->onQueue('translation');
    }

    public function handle(TranslationAdapter $translation, TTSAdapter $tts): void
    {
        $link = LocalizationLink::query()
            ->with(['localizationGroup.sourceProject.scenes', 'voiceProfile'])
            ->find($this->localizationLinkId);

        if (! $link || ! $link->localizationGroup || ! $link->localizationGroup->sourceProject) {
            return;
        }

        if ($link->localized_project_id && in_array($link->status, ['ready_for_review', 'completed'], true)) {
            $this->refreshGroupStatus((int) $link->localization_group_id);
            return;
        }

        $group = $link->localizationGroup;
        $sourceProject = $group->sourceProject;
        $sourceScenes = $sourceProject->scenes()->orderBy('scene_order')->get();

        if ($sourceScenes->isEmpty()) {
            throw new \RuntimeException('Source project has no scenes to localize.');
        }

        $group->forceFill(['status' => 'translating'])->save();
        $link->forceFill(['status' => 'translating'])->save();

        $texts = array_merge(
            [(string) ($sourceProject->title ?: 'Untitled Project')],
            $sourceScenes->pluck('script_text')->map(fn (mixed $text): string => (string) $text)->all(),
        );

        $translated = $translation->translate(
            $texts,
            (string) $group->source_language,
            (string) $link->target_language,
            'short-form faceless video project',
            true,
        );

        $translatedRows = $translated['translations'];
        $translatedTitle = trim((string) ($translatedRows[0]['translated'] ?? $sourceProject->title ?? 'Localized Project'));

        $group->forceFill(['status' => 'dub_generating'])->save();
        $link->forceFill(['status' => 'dub_generating'])->save();

        DB::transaction(function () use ($sourceProject, $sourceScenes, $link, $translatedRows, $translatedTitle, $tts): void {
            $localizedProject = Project::query()->create([
                'workspace_id' => $sourceProject->workspace_id,
                'channel_id' => $sourceProject->channel_id,
                'brand_kit_id' => $sourceProject->brand_kit_id,
                'template_id' => $sourceProject->template_id,
                'source_type' => $sourceProject->source_type,
                'source_content_raw' => $sourceProject->source_content_raw,
                'source_content_normalized' => $sourceProject->source_content_normalized,
                'content_goal' => $sourceProject->content_goal,
                'platform_target' => $sourceProject->platform_target,
                'duration_target_seconds' => $sourceProject->duration_target_seconds,
                'aspect_ratio' => $sourceProject->aspect_ratio,
                'tone' => $sourceProject->tone,
                'primary_language' => $link->target_language,
                'title' => $translatedTitle,
                'script_text' => collect(array_slice($translatedRows, 1))->pluck('translated')->implode("\n\n"),
                'status' => 'generating',
                'family_id' => $sourceProject->family_id ?: $sourceProject->getKey(),
                'created_by_user_id' => $sourceProject->created_by_user_id,
            ]);

            foreach ($sourceScenes as $index => $sourceScene) {
                $translatedText = trim((string) ($translatedRows[$index + 1]['translated'] ?? $sourceScene->script_text));
                $voiceId = $this->voiceIdForLink($link, $sourceScene);
                $speed = (float) data_get($sourceScene->voice_settings_json, 'speed', 1.0);
                $audio = $tts->synthesize($translatedText, (string) $link->target_language, $voiceId, $speed);

                $asset = Asset::query()->create([
                    'workspace_id' => $sourceProject->workspace_id,
                    'channel_id' => $sourceProject->channel_id,
                    'asset_type' => 'audio',
                    'title' => 'Localized TTS '.$link->target_language.' project '.$localizedProject->getKey().' scene '.$sourceScene->scene_order,
                    'description' => mb_substr($translatedText, 0, 180),
                    'storage_url' => $audio['audio_url'],
                    'duration_seconds' => $audio['duration_seconds'],
                    'mime_type' => 'audio/mpeg',
                    'tags' => ['tts', 'localized', $link->target_language, $audio['provider_key']],
                    'usage_count' => 1,
                    'status' => 'active',
                    'created_by_user_id' => $sourceProject->created_by_user_id,
                ]);

                $voiceSettings = $sourceScene->voice_settings_json ?? [];
                $voiceSettings['provider_key'] = $audio['provider_key'];
                $voiceSettings['voice_id'] = $audio['provider_voice_id'];
                $voiceSettings['speed'] = $speed;
                $voiceSettings['language'] = $link->target_language;
                $voiceSettings['audio_asset_id'] = $asset->getKey();
                $voiceSettings['localized_from_scene_id'] = $sourceScene->getKey();

                Scene::query()->create([
                    'project_id' => $localizedProject->getKey(),
                    'scene_order' => $sourceScene->scene_order,
                    'scene_type' => $sourceScene->scene_type,
                    'label' => $sourceScene->label,
                    'script_text' => $translatedText,
                    'duration_seconds' => $audio['duration_seconds'],
                    'voice_profile_id' => $link->voice_profile_id ?: $sourceScene->voice_profile_id,
                    'voice_settings_json' => $voiceSettings,
                    'caption_settings_json' => $sourceScene->caption_settings_json,
                    'visual_type' => $sourceScene->visual_type,
                    'visual_asset_id' => $sourceScene->visual_asset_id,
                    'visual_prompt' => $sourceScene->visual_prompt,
                    'transition_rule' => $sourceScene->transition_rule,
                    'status' => 'ready',
                    'locked_fields_json' => $sourceScene->locked_fields_json,
                ]);
            }

            $localizedProject->forceFill(['status' => 'ready_for_review'])->save();

            $link->forceFill([
                'localized_project_id' => $localizedProject->getKey(),
                'status' => 'completed',
            ])->save();
        });

        $this->refreshGroupStatus((int) $group->getKey());
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            $this->recordFailureTrace($exception, 'localization_link', $this->localizationLinkId);
        }

        $link = LocalizationLink::query()->find($this->localizationLinkId);

        if (! $link) {
            return;
        }

        $link->forceFill(['status' => 'failed'])->save();
        $this->refreshGroupStatus((int) $link->localization_group_id);
    }

    private function voiceIdForLink(LocalizationLink $link, Scene $sourceScene): string
    {
        if ($link->voiceProfile instanceof VoiceProfile) {
            return (string) $link->voiceProfile->provider_voice_key;
        }

        return (string) data_get($sourceScene->voice_settings_json, 'voice_id', 'alloy');
    }

    private function refreshGroupStatus(int $groupId): void
    {
        $group = LocalizationGroup::query()->with('links')->find($groupId);

        if (! $group) {
            return;
        }

        $links = $group->links;
        $failed = $links->where('status', 'failed')->count();
        $completed = $links->where('status', 'completed')->count();
        $active = $links->whereIn('status', ['pending', 'translating', 'dub_generating'])->count();

        $status = match (true) {
            $completed === $links->count() && $links->count() > 0 => 'completed',
            $completed > 0 && $failed > 0 && $active === 0 => 'partial_success',
            $failed === $links->count() && $links->count() > 0 => 'failed',
            $active > 0 => 'dub_generating',
            default => 'pending',
        };

        $group->forceFill(['status' => $status])->save();
    }
}
