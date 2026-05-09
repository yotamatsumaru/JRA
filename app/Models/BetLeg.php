<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 馬券買い目（組合せ1点）
 *
 * @property int $id
 * @property int $bet_id
 * @property string $combination  例 "3" "3-7" "3-7-1"
 * @property int $stake
 * @property bool $is_hit
 * @property int $payout
 * @property int|null $payout_popularity
 */
class BetLeg extends Model
{
    use HasFactory;

    protected $fillable = [
        'bet_id', 'combination', 'stake',
        'is_hit', 'payout', 'payout_popularity',
    ];

    protected function casts(): array
    {
        return [
            'is_hit' => 'boolean',
        ];
    }

    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    /** 損益（この1点） */
    public function getProfitAttribute(): int
    {
        return (int) ($this->payout - $this->stake);
    }

    /** 馬番配列 */
    public function getNumbersAttribute(): array
    {
        return array_map('intval', explode('-', $this->combination));
    }
}
