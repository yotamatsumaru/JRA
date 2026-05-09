<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OddsSnapshot extends Model
{
    use HasFactory;

    protected $fillable = ['race_id', 'captured_at', 'source', 'payload'];

    protected $casts = [
        'captured_at' => 'datetime',
        'payload'     => 'array',
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }
}
