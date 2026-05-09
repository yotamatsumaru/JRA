<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ウォッチリスト (Phase 4-W)
 *
 *  注目馬・騎手・厩舎を登録し、出走予定/直近成績を一覧表示する。
 *  Favorites との違い:
 *   - メモ (なぜ注目しているか) と alert_on_entry (出走時アラート) を持つ
 *   - last_alerted_at で一度通知した出走を覚える
 */
class Watchlist extends Model
{
    use HasFactory;

    public const TYPE_MAP = [
        'horse'   => \App\Models\Horse::class,
        'jockey'  => \App\Models\Jockey::class,
        'trainer' => \App\Models\Trainer::class,
    ];

    public const TYPE_LABELS = [
        'horse'   => '馬',
        'jockey'  => '騎手',
        'trainer' => '厩舎',
    ];

    protected $fillable = [
        'user_id', 'target_type', 'target_id', 'label',
        'memo', 'alert_on_entry', 'last_alerted_at',
    ];

    protected $casts = [
        'alert_on_entry'  => 'boolean',
        'last_alerted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 対象モデル(動的に解決) */
    public function target(): ?Model
    {
        $cls = self::TYPE_MAP[$this->target_type] ?? null;
        if (!$cls) return null;
        return $cls::find($this->target_id);
    }

    public static function classFor(string $type): ?string
    {
        return self::TYPE_MAP[$type] ?? null;
    }
}
