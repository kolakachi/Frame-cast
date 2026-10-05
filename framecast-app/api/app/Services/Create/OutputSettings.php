<?php
namespace App\Services\Create;
class OutputSettings
{
    public static function rules(): array { return [
        'aspect_ratio'=>'sometimes|in:9:16,16:9,1:1,4:5','duration_seconds'=>'sometimes|integer|min:5|max:30',
        'language'=>'sometimes|string|in:en,fr,es,de,pt,it,nl,ar,hi,ja,ko,zh',
        'audio'=>'sometimes|in:original,silent','captions'=>'sometimes|in:off,provided',
        'caption_text'=>'nullable|string|max:4000','approved_facts'=>'sometimes|array|max:20',
        'approved_facts.*'=>'string|max:500', 'output_kind'=>'sometimes|in:video,image','video_mode'=>'sometimes|in:composition,animate_image',
        'origin_conversation_id'=>'sometimes|uuid','origin_revision_id'=>'sometimes|uuid','style_id'=>'sometimes|nullable|uuid','style_pack'=>'sometimes|nullable|string|max:40',
        'motion_blur'=>'sometimes|boolean','reference_effort'=>'sometimes|nullable|in:standard,high,maximum','reference_match'=>'sometimes|nullable|in:inspired,similar,exact','media_ceiling_credits'=>'sometimes|nullable|integer|min:0|max:20000',
        // Set when the user picks the length (Details or the brief), so an exact copy does not override it with the reference's.
        'duration_chosen'=>'sometimes|boolean',
    ]; }
    public static function normalize(array $input): array {
        abort_if(array_diff(array_keys($input),array_filter(array_keys(self::rules()),fn($k)=>!str_contains($k,'.'))),422,'Unsupported output setting.');
        $s=validator($input,self::rules())->validate();
        $s+=['output_kind'=>'video','aspect_ratio'=>'9:16','duration_seconds'=>15,'language'=>'en','audio'=>'original','captions'=>'off','approved_facts'=>[],'motion_blur'=>false];
        abort_if(!empty($s['style_pack']) && !StylePacks::exists($s['style_pack']),422,'That style is not available.');
        abort_if(!empty($s['style_pack']) && !empty($s['style_id']),422,'Choose one style: a WyvStudio style or one of yours.');
        abort_if($s['captions']==='provided' && !trim($s['caption_text']??''),422,'Provide the exact caption text first.');
        abort_if(($s['video_mode']??'composition')==='animate_image' && ($s['output_kind']!=='video' || !in_array($s['duration_seconds'],[5,10],true)),422,'Image animation supports 5 or 10 seconds.');
        return $s;
    }
    public static function dimensions(string $ratio): array { return match($ratio) { '16:9'=>[1920,1080], '1:1'=>[1080,1080], '4:5'=>[1080,1350], default=>[1080,1920] }; }
}
