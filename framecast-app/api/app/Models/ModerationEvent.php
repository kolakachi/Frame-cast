<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationEvent extends Model
{
    use HasFactory;

    public const SOURCE_GENERATION_REJECTION = 'generation_rejection';
    public const SOURCE_USER_REPORT          = 'user_report';
    public const SOURCE_PATTERN_ALERT        = 'pattern_alert';
    public const SOURCE_ADMIN_ACTION         = 'admin_action';

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_LOW      = 'low';
    public const SEVERITY_MEDIUM   = 'medium';
    public const SEVERITY_HIGH     = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    public const ACTION_NO_ACTION             = 'no_action';
    public const ACTION_WARNING_SENT          = 'warning_sent';
    public const ACTION_CONTENT_REMOVED       = 'content_removed';
    public const ACTION_FEATURE_SUSPENDED     = 'feature_suspended';
    public const ACTION_ACCOUNT_SUSPENDED     = 'account_suspended';
    public const ACTION_WORKSPACE_TERMINATED  = 'workspace_terminated';
    public const ACTION_REPORTED_TO_AUTHORITIES = 'reported_to_authorities';

    protected $fillable = [
        'source',
        'severity',
        'workspace_id',
        'user_id',
        'project_id',
        'scene_id',
        'operation',
        'reason',
        'prompt',
        'reference_asset_id',
        'resulting_asset_id',
        'report_email',
        'report_url',
        'report_message',
        'report_violation_type',
        'metadata',
        'reviewed_at',
        'reviewed_by_user_id',
        'action_taken',
        'action_notes',
    ];

    protected function casts(): array
    {
        return [
            'metadata'    => 'array',
            'reviewed_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
