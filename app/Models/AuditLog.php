<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'action', 'subject_type', 'subject_id',
        'route_name', 'ip', 'user_agent', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 簡易記録ヘルパ
     */
    public static function record(string $action, ?Model $subject = null, array $meta = [], ?int $userId = null): self
    {
        return self::create([
            'user_id'      => $userId ?? auth()->id(),
            'action'       => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id'   => $subject?->getKey(),
            'route_name'   => optional(request()->route())->getName(),
            'ip'           => request()->ip(),
            'user_agent'   => substr((string) request()->userAgent(), 0, 500),
            'meta'         => $meta ?: null,
        ]);
    }

    /**
     * よく使う action ラベル
     */
    public const ACTIONS = [
        'bet.create'             => '馬券登録',
        'bet.update'             => '馬券更新',
        'bet.delete'             => '馬券削除',
        'bet.settle'             => '馬券精算',
        'bet.settle_all'         => '一括精算',
        'shutuba.mark'           => '印更新',
        'shutuba.auto_mark'      => '印自動提案',
        'shutuba.memo'           => '馬メモ更新',
        'shutuba.race_note'      => 'レースメモ',
        'shutuba.generate_bets'  => '印別馬券生成',
        'favorite.toggle'        => 'お気に入り',
        'bankroll.update'        => 'バンクロール更新',
        'bankroll.delete'        => 'バンクロール削除',
        'import.run'             => '取込実行',
        'scheduler.run'          => 'スケジューラ実行',
        'odds.capture'           => 'オッズ取得',
        'backup.run'             => 'バックアップ実行',
        // Phase 4-S
        'share.create'           => '予想スナップショット作成',
        'share.toggle'           => '共有状態切替',
        'share.delete'           => '共有削除',
        // Phase 4-W
        'watchlist.add'          => 'ウォッチリスト追加',
        'watchlist.update'       => 'ウォッチリスト更新',
        'watchlist.remove'       => 'ウォッチリスト削除',
    ];
}
