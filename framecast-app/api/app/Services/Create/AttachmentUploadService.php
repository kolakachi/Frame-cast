<?php
namespace App\Services\Create;

use App\Models\{Asset, User, Workspace};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;

/** Private local intake only. Uploading never dispatches transcription or generation. */
class AttachmentUploadService
{
    public const TYPES = ['image/png'=>['image','png'], 'image/jpeg'=>['image','jpg'], 'image/webp'=>['image','webp'],
        'video/mp4'=>['video','mp4'], 'audio/mpeg'=>['audio','mp3'], 'audio/wav'=>['audio','wav'], 'audio/x-wav'=>['audio','wav']];

    public function upload(User $user, string $conversationId, UploadedFile $file, string $purpose, string $key, int $version): Asset
    {
        $service = app(ConversationService::class);
        $service->authorize($user, true);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $type = self::TYPES[$mime] ?? null;
        abort_unless($file->isValid() && $type && $file->getSize() > 0 && $file->getSize() <= config('create.input_file_bytes'), 422, 'Use PNG, JPEG, WebP, MP4, MP3 or WAV, up to 100 MB per file.');
        abort_unless(in_array($purpose,['source','reference'],true),422);
        $hash = hash_file('sha256',$file->getRealPath());
        $written = null;
        try {
            return DB::transaction(function () use ($user,$conversationId,$file,$purpose,$key,$version,$service,$type,$mime,$hash,&$written) {
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
                abort_if($total + $file->getSize() > config('create.input_total_bytes'),422,'Use at most 200 MB of attachments per conversation.');
                $stored = Asset::where('workspace_id',$user->workspace_id)->where('storage_url','like','create-upload://%')->sum('file_size_bytes');
                abort_if($stored + $file->getSize() > config('create.input_workspace_bytes'),422,'Local upload storage is full. Existing files are preserved.');
                $suffix = $user->workspace_id.'/'.Str::uuid().'/'.$hash.'.'.$type[1];
                $written = 'create/uploads/'.$suffix;
                $stream = fopen($file->getRealPath(),'rb');
                try { abort_unless(Storage::disk('local')->put($written,$stream,['visibility'=>'private']),503,'Upload storage is unavailable.'); }
                finally { if(is_resource($stream)) fclose($stream); }
                $asset = Asset::create(['workspace_id'=>$user->workspace_id,'created_by_user_id'=>$user->id,
                    'title'=>mb_substr(basename($file->getClientOriginalName()),0,255),'asset_type'=>$type[0],
                    'storage_url'=>'create-upload://'.$suffix,'mime_type'=>$mime,'file_size_bytes'=>$file->getSize(),
                    'transcription_status'=>'not_requested','status'=>'active','restriction_scope'=>'workspace',
                    'metadata_json'=>['create_upload_key'=>$key,'conversation_id'=>$conversationId,'sha256'=>$hash,'purpose'=>$purpose]]);
                $service->attach($user,$conversationId,$asset->id,$purpose,$version);
                return $asset;
            });
        } catch (\Throwable $e) { if($written) Storage::disk('local')->delete($written); throw $e; }
    }
}
