<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * アプリ内通知 (Phase 6-A)
 *
 *  type:
 *    - watchlist_entry : ウォッチ対象の出走予定通知
 *    - share_expiring  : 共有予想の有効期限が近い
 *    - system          : 任意のシステム通知
 */
class AppNotification extends Model
{
    use HasFactory;

    protected $table = 'app_notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'link', 'payload', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function scopeUnread($q)
    {
        return $q->whereNull('read_at');
    }

    public function scopeForUser($q, int $userId)
    {
        return $q->where('user_id', $userId);
    }
}
