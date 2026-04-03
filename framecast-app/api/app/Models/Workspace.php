<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Channel;
use App\Models\BrandKit;
use App\Models\VoiceProfile;
use App\Models\CaptionPreset;
use App\Models\Template;
use App\Models\Project;

class Workspace extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'owner_user_id',
        'plan_tier',
        'status',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function brandKits(): HasMany
    {
        return $this->hasMany(BrandKit::class);
    }

    public function voiceProfiles(): HasMany
    {
        return $this->hasMany(VoiceProfile::class);
    }

    public function captionPresets(): HasMany
    {
        return $this->hasMany(CaptionPreset::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
