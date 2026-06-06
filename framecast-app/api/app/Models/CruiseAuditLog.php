<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CruiseAuditLog extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'project_id',
        'scene_id',
        'phase',
        'intent_text',
        'resolved_tool',
        'resolved_params',
        'applied',
        'credits_spent',
        'outcome',
        'error_message',
    ];

    protected $casts = [
        'resolved_params' => 'array',
        'applied' => 'boolean',
        'credits_spent' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
