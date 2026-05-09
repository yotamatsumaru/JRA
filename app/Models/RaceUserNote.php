<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * レース全体メモ (Phase 1-T)
 *
 * 出走馬個別の race_marks.memo とは別に、レース単位での
 * 「展開予想」「次走注目」「全体所感」などを保存する。
 */
class RaceUserNote extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'race_id', 'note', 'watch_next'];

    protected function casts(): array
    {
        return [
            'watch_next' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }
}
