<?php
namespace App\Services\Create;

use App\Models\{Asset, User, Workspace};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;

/** Private Create intake. Uploading never dispatches transcription or generation. */
class AttachmentUploadService
{
    public const TYPES = ['image/png'=>['image','png'], 'image/jpeg'=>['image','jpg'], 'image/webp'=>['image','webp'],
        'video/mp4'=>['video','mp4'], 'audio/mpeg'=>['audio','mp3'], 'audio/wav'=>['audio','wav'], 'audio/x-wav'=>['audio','wav'], 'image/svg+xml'=>['image','svg']];

    public function upload(User $user, string $conversationId, UploadedFile $file, string $purpose, string $key, int $version): Asset
    {
        $service = app(ConversationService::class);
        $service->authorize($user, true);
        app(AdmissionControl::class)->assertOpen();
        app(DiskSpace::class)->admission();
        $path = $file->getRealPath(); $size = (int) $file->getSize(); $rig = null;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        // An SVG (a character drawn in layers for the rig) is cleaned before it is stored, and checked against the rig contract.
        if (! isset(self::TYPES[$mime]) && $size <= RigSvg::MAX_BYTES && RigSvg::looksLike((string) file_get_contents($path, false, null, 0, 4096))) $mime = 'image/svg+xml';
        if ($mime === 'image/svg+xml') {
            $prepared = RigSvg::prepare((string) file_get_contents($path));
            $path = tempnam(sys_get_temp_dir(), 'rig'); file_put_contents($path, $prepared['svg']); $size = (int) filesize($path); $rig = $prepared['rig'];
        }
        $type = self::TYPES[$mime] ?? null;
        abort_unless($file->isValid() && $type && $size > 0 && $size <= config('create.input_file_bytes'), 422, 'Use PNG, JPEG, WebP, SVG, MP4, MP3 or WAV, up to 100 MB per file.');
        abort_unless(in_array($purpose,['source','reference','auto'],true),422);
        // Footage the video will cut from is made renderable once, here (references are only studied). A file whose
        // role is not decided yet is prepared too, so it is ready if the brief puts it in the video.
        if ($purpose !== 'reference' && $type[0] === 'video') {
            try { $path = VideoIntake::prepare($path); } catch (\RuntimeException $e) { abort(422, $e->getMessage()); }
            $size = (int) filesize($path);
            abort_unless($size <= config('create.input_file_bytes'), 422, 'That video is over 100 MB once prepared. Upload a shorter clip.');
        }
        $hash = hash_file('sha256',$path);
        $written = null;
        try {
            return DB::transaction(function () use ($user,$conversationId,$file,$path,$size,$rig,$purpose,$key,$version,$service,$type,$mime,$hash,&$written) {
                Workspace::whereKey($user->workspace_id)->lockForUpdate()->firstOrFail();
                $c = $service->conversation($user,$conversationId,true);
                abort_if($c->archived_at,409,'Restore this conversation before adding files.');
                $old = Asset::where('workspace_id',$user->workspace_id)->where('metadata_json->create_upload_key',$key)->first();
                if ($old) {
                    abort_unless(data_get($old->metadata_json,'conversation_id') === $conversationId
                        && data_get($old->metadata_json,'sha256') === $hash && data_get($old->metadata_json,'purpose') === $purpose,
                        409,'This upload key belongs to a different file or request.');
                    // Replay cannot undo a later removal or silently reattach the file.
                    return $old;
                }
                abort_unless((int)$c->version === $version,409,'Conversation changed. Refresh before uploading again.');
                $attached = DB::table('create_attachments')->where('conversation_id',$c->id);
                abort_if((clone $attached)->count() >= 20,422,'Use at most 20 attachments.');
                $total = Asset::whereIn('id',(clone $attached)->pluck('asset_id'))->sum('file_size_bytes');
                abort_if($total + $size > config('create.input_total_bytes'),422,'Use at most 200 MB of attachments per conversation.');
                // Files still in use: an archived upload's bytes are gone or going, so it no longer counts.
                $stored = Asset::where('workspace_id',$user->workspace_id)->where('storage_url','like','create-upload://%')->where('status','!=','archived')->sum('file_size_bytes');
                abort_if($stored + $size > config('create.input_workspace_bytes'),422,'Local upload storage is full. Existing files are preserved.');
                $suffix = $user->workspace_id.'/'.Str::uuid().'/'.$hash.'.'.$type[1];
                $written = 'create/uploads/'.$suffix;
                $stream = fopen($path,'rb');
                try { abort_unless(app(\App\Services\Create\CreateStorage::class)->put($written,$stream,['visibility'=>'private']),503,'Upload storage is unavailable.'); }
                finally { if(is_resource($stream)) fclose($stream); }
                $asset = Asset::create(['workspace_id'=>$user->workspace_id,'created_by_user_id'=>$user->id,
                    'title'=>mb_substr(basename($file->getClientOriginalName()),0,255),'asset_type'=>$type[0],
                    'storage_url'=>'create-upload://'.$suffix,'mime_type'=>$mime,'file_size_bytes'=>$size,
                    'transcription_status'=>'not_requested','status'=>'active','restriction_scope'=>'workspace',
                    'metadata_json'=>['create_upload_key'=>$key,'conversation_id'=>$conversationId,'sha256'=>$hash,'purpose'=>$purpose]+($rig?['rig'=>$rig]:[])]);
                $service->attach($user,$conversationId,$asset->id,$purpose,$version);
                return $asset;
            });
        } catch (\Throwable $e) { if($written) app(\App\Services\Create\CreateStorage::class)->delete($written); throw $e; }
    }
}
