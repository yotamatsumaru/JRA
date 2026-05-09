<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * お気に入り (Phase 1-M)
 *
 * Polymorphic 関連で 馬 / 騎手 / 厩舎 などを対象にする。
 * favoritable_type には対象モデルのクラス名 (App\Models\Horse, App\Models\Jockey, App\Models\Trainer) が入る。
 */
class Favorite extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'favoritable_type',
        'favoritable_id',
        'memo',
    ];

    /** UI 用の type 文字列 → モデルクラスのマップ */
    public const TYPE_MAP = [
        'horse'   => \App\Models\Horse::class,
        'jockey'  => \App\Models\Jockey::class,
        'trainer' => \App\Models\Trainer::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 指定ユーザの指定タイプ(horse|jockey|trainer)のお気に入り対象 ID を配列で取得
     *
     * @return int[]
     */
    public static function userKey(int $userId, string $type): array
    {
        $cls = self::TYPE_MAP[$type] ?? null;
        if (!$cls) return [];

        return self::where('user_id', $userId)
            ->where('favoritable_type', $cls)
            ->pluck('favoritable_id')
            ->map(fn($v) => (int) $v)
            ->all();
    }

    /**
     * UI の type 文字列をモデルクラス名に変換
     */
    public static function classFor(string $type): ?string
    {
        return self::TYPE_MAP[$type] ?? null;
    }
}
