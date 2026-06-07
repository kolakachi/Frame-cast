<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CruiseActionRun extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'project_id',
        'message_id',
        'action_index',
        'tool',
        'params_json',
        'expected_stages',
        'completed_stages',
        'status',
        'estimated_credits',
        'actual_credits',
        'affected_scene_id',
        'error_message',
    ];

    protected $casts = [
        'params_json' => 'array',
        'expected_stages' => 'array',
        'completed_stages' => 'array',
        'estimated_credits' => 'integer',
        'actual_credits' => 'integer',
        'affected_scene_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
