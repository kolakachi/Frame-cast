<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalizationLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'localization_group_id',
        'target_language',
        'localized_project_id',
        'voice_profile_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function localizationGroup(): BelongsTo
    {
        return $this->belongsTo(LocalizationGroup::class);
    }

    public function localizedProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'localized_project_id');
    }

    public function voiceProfile(): BelongsTo
    {
        return $this->belongsTo(VoiceProfile::class);
    }
}
