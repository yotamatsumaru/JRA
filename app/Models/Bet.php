<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 馬券購入（ヘッダ）
 *
 * @property int $id
 * @property int $user_id
 * @property int $race_id
 * @property string $kind  券種コード
 * @property string $method  single|box|formation
 * @property int $unit_stake
 * @property int $points
 * @property int $total_stake
 * @property int $hit_count
 * @property int $total_return
 * @property bool $is_settled
 * @property array|null $selection
 * @property \Illuminate\Support\Carbon|null $purchased_at
 * @property string|null $memo
 */
class Bet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'race_id', 'kind', 'method',
        'unit_stake', 'points', 'total_stake',
        'hit_count', 'total_return', 'is_settled',
        'selection', 'purchased_at', 'memo',
    ];

    protected function casts(): array
    {
        return [
            'selection' => 'array',
            'purchased_at' => 'datetime',
            'is_settled' => 'boolean',
        ];
    }

    /** 券種ラベルマップ（表示用） */
    public const KIND_LABELS = [
        'tan'      => '単勝',
        'fuku'     => '複勝',
        'waku-ren' => '枠連',
        'uma-ren'  => '馬連',
        'uma-tan'  => '馬単',
        'wide'     => 'ワイド',
        'san-fuku' => '3連複',
        'san-tan'  => '3連単',
        'win5'     => 'WIN5',
    ];

    public const METHOD_LABELS = [
        'single'     => '単発',
        'box'        => 'ボックス',
        'formation'  => 'フォーメーション',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(BetLeg::class);
    }

    // ----- アクセサ -----

    public function getKindLabelAttribute(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    public function getMethodLabelAttribute(): string
    {
        return self::METHOD_LABELS[$this->method] ?? $this->method;
    }

    /** 損益（円） */
    public function getProfitAttribute(): int
    {
        return (int) ($this->total_return - $this->total_stake);
    }

    /** 回収率（%） */
    public function getRoiAttribute(): ?float
    {
        if ($this->total_stake <= 0) return null;
        return round($this->total_return / $this->total_stake * 100, 1);
    }

    public function getIsHitAttribute(): bool
    {
        return $this->hit_count > 0;
    }

    /** ステータスラベル */
    public function getStatusLabelAttribute(): string
    {
        if (!$this->is_settled) return '未確定';
        return $this->hit_count > 0 ? '的中' : '不的中';
    }
}
