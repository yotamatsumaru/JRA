<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * 予想スナップショット共有 (Phase 4-S)
 *
 * 印・スコア・メモを公開URLで read-only 共有する。
 *  - token は 32 文字のランダム英数字
 *  - 失効日時 (expires_at) を過ぎた / is_active=false のものは閲覧不可
 *  - snapshot は { title, race, rows: [{horse_no, horse_name, mark, score, memo, ...}] }
 */
class PredictionShare extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'race_id', 'token', 'title', 'comment',
        'snapshot', 'expires_at', 'is_active',
        'view_count', 'last_viewed_at',
    ];

    protected $casts = [
        'snapshot'       => 'array',
        'expires_at'     => 'datetime',
        'last_viewed_at' => 'datetime',
        'is_active'      => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /** 新規トークン発行 */
    public static function generateToken(): string
    {
        do {
            $t = Str::lower(Str::random(32));
        } while (self::where('token', $t)->exists());
        return $t;
    }

    /** 公開中か */
    public function isViewable(): bool
    {
        if (!$this->is_active) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }

    public function getPublicUrlAttribute(): string
    {
        return route('share.show', $this->token);
    }
}
