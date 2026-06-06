<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CruiseConversation extends Model
{
    protected $fillable = [
        'workspace_id',
        'project_id',
        'user_id',
        'messages',
        'message_count',
        'last_activity_at',
    ];

    protected $casts = [
        'messages' => 'array',
        'message_count' => 'integer',
        'last_activity_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
