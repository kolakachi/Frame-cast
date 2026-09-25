<?php

namespace App\Services\Developer;

use App\Constants\CaptionFonts;
use App\Services\CreditService;
use Illuminate\Support\Facades\Validator;

/** Discovery and proposal validation share these field definitions. */
class EditorSettings
{
    private static function field(string $type, string $rules = '', string $description = '', mixed $default = null): array
    {
        $schema = compact('type', 'rules', 'description', 'default');
        $types = explode('|', $type);
        $schema['type'] = count($types) > 1 ? $types : $type;
        foreach (explode('|', $rules) as $rule) {
            if (str_starts_with($rule, 'in:')) $schema['enum'] = explode(',', substr($rule, 3));
            if (str_starts_with($rule, 'min:')) $schema[in_array('string', $types) ? 'minLength' : 'minimum'] = (float) substr($rule, 4);
            if (str_starts_with($rule, 'max:')) $schema[in_array('string', $types) ? 'maxLength' : 'maximum'] = (float) substr($rule, 4);
        }
        return $schema;
    }

    public static function groups(): array
    {
        $f = self::field(...);
        return [
            'voice_settings_json' => ['semantics' => 'Shallow merge. Null/empty object preserves saved settings. Clear an individual nullable field explicitly. Server owns audio freshness.', 'fields' => [
                'voice_id' => $f('string', 'string|max:150', 'ID from list_voices; clone identity overrides provider.'),
                'provider' => $f('string', 'string|in:openai,google,gemini,chatterbox,clone', 'Absent: inferred from voice ID.'),
                'speed' => $f('number', 'numeric|min:0.25|max:4', 'Playback multiplier. OpenAI native; Gemini/clones use tempo processing.', 1),
                'stability' => $f('string', 'string|in:low,medium,high', 'Legacy saved preference; current adapters do not consume it. No audible effect promised.', 'medium'),
                'voice_prompt' => $f('string|null', 'nullable|string|max:2000', 'Delivery direction: used by Gemini only; other engines ignore it.'),
                'language' => $f('string', 'string|max:16', 'Single-scene regeneration uses this language; bulk uses project primary_language.'),
                'volume' => $f('number', 'numeric|min:0|max:200', 'Percent mix gain, not generation; 0 mutes.', 100),
                'audio_asset_id' => $f('integer|null', 'nullable|integer', 'Existing workspace audio. A new asset clears stale narration; null detaches audio.'),
            ]],
            'caption_settings_json' => ['semantics' => 'Replace object; retain ugc_headline if omitted. Null resets captions to defaults. Headline text is laid out server-side.', 'fields' => [
                'enabled' => $f('boolean', 'boolean', '', true),
                'style_key' => $f('string', 'string|in:impact,editorial,hacker', '', 'impact'),
                'highlight_mode' => $f('string', 'string|in:keywords,word_by_word,line_by_line,none', '', 'keywords'),
                'position' => $f('string', 'string|in:bottom_third,center,top_third', '', 'bottom_third'),
                'font' => $f('string|null', 'nullable|string|in:'.implode(',', CaptionFonts::ALL), 'Null uses style default.'),
                'highlight_color' => $f('string|null', 'nullable|string|max:32', 'CSS color; use #RRGGBB.'),
                'color' => $f('string|null', 'nullable|string|max:32', 'CSS color; use #RRGGBB.'),
                'size' => $f('string|null', 'nullable|string|in:small,medium,large,xlarge', '', 'medium'),
                'preset_id' => $f('integer|null', 'nullable|integer', 'Preset identity only: copy resolved settings from list_caption_presets; does not expand a preset.'),
                'animation' => $f('string|null', 'nullable|string|in:plain,beast,comic,sticker,karaoke,box,stream,blur,glitch,slide,wave,punch,tracking,neon,news,marker', '', 'plain'),
                'highlight_style' => $f('string|null', 'nullable|string|in:color,underline,plain', '', 'color'),
                'panel_color' => $f('string|null', 'nullable|string|max:64', 'CSS color, including transparent.'),
                'backdrop' => $f('boolean|null', 'nullable|boolean'),
                'ugc_headline' => $f('object|null', 'nullable|array:text', 'Only text (nullable string, max 180); empty clears headline.'),
            ]],
            'motion_settings_json' => ['semantics' => 'Replace object. Null uses render defaults. Motion applies to still images; fit also affects framing.', 'fields' => [
                'effect' => $f('string', 'string|in:zoom_in,zoom_out,pan_left,pan_right,pan_up,pan_down,pan_zoom,static', '', 'zoom_in'),
                'intensity' => $f('string', 'string|in:subtle,moderate,dramatic', '', 'moderate'),
                'fit' => $f('string', 'string|in:fit,crop', 'fit contains the full image; crop fills the frame.', 'crop'),
            ]],
            'sound_settings_json' => ['semantics' => 'Replace object. Null uses default gain. sound_asset_id is separate.', 'fields' => [
                'volume' => $f('number', 'numeric|min:0|max:200', 'Percent gain; 0 mutes.', 100),
            ]],
            'music_settings_json' => ['semantics' => 'Replace object; null resets defaults. music_asset_id=null clears the selected track.', 'fields' => [
                'volume' => $f('integer', 'integer|min:0|max:100', 'Values 2–100 are percent; legacy renderer treats 1 as full gain. Use 0 to mute.', 30),
                'duck_volume' => $f('integer', 'integer|min:0|max:100', 'Percent gain under narration.', 8),
                'fade_in_ms' => $f('integer', 'integer|min:0|max:5000', 'Milliseconds.', 500),
                'loop' => $f('boolean', 'boolean', '', true),
                'duck_during_voice' => $f('boolean', 'boolean', '', true),
            ]],
            'image_generation_settings_json' => ['semantics' => 'Read-only generation state in the developer API. Use generate_image, animate or use_animation_history; never write job tokens or history.', 'read_only' => true, 'fields' => []],
        ];
    }

    public static function sceneFields(): array
    {
        $fields = [];
        foreach (EditOperations::SCENE_SETTINGS as $name) {
            $type = str_ends_with($name, '_id') ? 'integer|null' : (str_ends_with($name, '_json') ? 'object|null' : 'string|null');
            if ($name === 'duration_seconds') $type = 'number|null';
            if ($name === 'locked_fields_json') $type = 'array<string>|null';
            $fields[$name] = ['type' => $type, 'omitted' => 'preserve', 'null' => 'clear/reset; JSON exceptions described in groups'];
        }
        $fields['duration_seconds'] += ['minimum' => 0, 'maximum' => 600, 'unit' => 'seconds', 'note' => 'Narration audio owns duration when present; regenerate it instead.'];
        $fields['visual_style']['values_from'] = 'enums.visual_styles';
        $fields['voice_profile_id']['note'] = 'Use an owned profile from list_voices; synthesis consumes voice_settings_json.voice_id, so set that explicitly.';
        $fields['image_generation_settings_json']['read_only'] = true;
        return $fields;
    }

    public static function validate(array $settings): array
    {
        $rules = ['locked_fields_json' => ['sometimes', 'nullable', 'array'], 'locked_fields_json.*' => ['string', 'in:'.implode(',', EditOperations::SCENE_SETTINGS)]];
        foreach (self::groups() as $group => $definition) {
            if (! array_key_exists($group, $settings)) continue;
            if (! empty($definition['read_only'])) return [$group => ['Generation state is read-only; use a generation operation.']];
            $rules[$group] = ['nullable', 'array:'.implode(',', array_keys($definition['fields']))];
            foreach ($definition['fields'] as $key => $field) $rules["{$group}.{$key}"] = 'sometimes|'.$field['rules'];
        }
        $rules['caption_settings_json.ugc_headline.text'] = 'sometimes|nullable|string|max:180';
        return Validator::make($settings, $rules)->errors()->toArray();
    }

    public static function discovery(): array
    {
        $tiers = [];
        foreach (CreditService::VIDEO_PRICING as $tier => $cfg) $tiers[$tier] = ['quality_options' => array_keys($cfg['options']), 'default_quality' => $cfg['default'], 'duration_seconds' => ['minimum' => 3, 'maximum' => 10, 'normalization' => '3–7 -> 5; 8–10 -> 10; provider may return its native duration.']];
        $tiers['spokesperson'] = ['quality_options' => [], 'duration' => 'Actual narration asset duration, fallback scene duration then 8 seconds. Re-record stale narration first.', 'lipsync_engines' => array_keys((array) config('services.lipsync.engines', [])), 'default_engine' => config('services.lipsync.default'), 'consent' => 'Required for likeness use.'];
        return ['scene_fields' => self::sceneFields(), 'groups' => self::groups(), 'animation' => $tiers,
            'transition_rule' => ['type' => 'string|null', 'max_length' => 64, 'description' => 'Legacy stored field; current export renderer does not use it. Do not promise a transition effect.'],
            'project_selections' => ['channel_id', 'brand_kit_id', 'music_asset_id'],
            'selection_nulls' => 'Omitted preserves; explicit null clears. Aspect ratio cannot change after creation.',
            'stale_state' => 'Narration is_outdated and animation_outdated are server-owned. Script/voice edits require re-recording; stale lip-sync requires re-animation before export.',
            'locks' => 'locked_fields_json is an array of field names. Bulk skips relevant locked fields; explicit edits require unlocking first. Do not remove locks without user approval.',
            'whole_video' => 'one_shot/restyle takes reject scene and bulk operations; edit them through their creation flow.',
            'rewrite' => 'rewrite_scene generates and applies immediately AFTER approval; no preview. For exact reviewed wording, use update_scene.script_text.',
            'image_generation' => 'style/model_key/prompt_override configure this render; on success the job saves the resulting prompt, style and model back to the scene. These are not ephemeral previews. Future renders without explicit model_key use the configured default.',
        ];
    }
}
