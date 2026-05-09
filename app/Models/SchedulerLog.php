<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchedulerLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'job', 'status', 'started_at', 'finished_at',
        'duration_ms', 'exit_code', 'output', 'error',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'exit_code'   => 'integer',
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
}
