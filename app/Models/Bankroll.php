<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bankroll extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'ym', 'target_stake', 'target_profit', 'notes',
    ];

    protected $casts = [
        'target_stake'  => 'integer',
        'target_profit' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
