<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'provider',
        'name',
        'language',
        'accent',
        'gender_label',
        'voice_type',
        'is_cloned',
        'source_asset_id',
        'provider_voice_key',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_cloned' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
