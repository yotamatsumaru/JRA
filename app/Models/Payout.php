<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * レースの公式払戻（券種ごとの的中組合せ）
 *
 * @property int $id
 * @property int $race_id
 * @property string $kind
 * @property string $combination
 * @property int $amount
 * @property int|null $popularity
 */
class Payout extends Model
{
    use HasFactory;

    protected $fillable = [
        'race_id', 'kind', 'combination', 'amount', 'popularity',
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function getKindLabelAttribute(): string
    {
        return Bet::KIND_LABELS[$this->kind] ?? $this->kind;
    }
}
