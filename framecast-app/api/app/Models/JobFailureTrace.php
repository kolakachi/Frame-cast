<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobFailureTrace extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'job_class',
        'entity_type',
        'entity_id',
        'workspace_id',
        'project_id',
        'exception_class',
        'exception_message',
        'exception_trace',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
        ];
    }

    /** Human-readable short class name for display. */
    public function jobLabel(): string
    {
        return class_basename($this->job_class);
    }
}
