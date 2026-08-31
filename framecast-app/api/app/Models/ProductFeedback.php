<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductFeedback extends Model
{
    protected $table = 'product_feedback';

    protected $fillable = [
        'workspace_id', 'user_id', 'rating', 'options', 'comment',
        'trigger', 'project_id', 'export_job_id',
    ];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}
