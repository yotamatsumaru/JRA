<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * レース結果（出走馬1頭分）
 */
class RaceResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'race_id',
        'horse_id',
        'jockey_id',
        'trainer_id',
        'finish_position',
        'finish_position_int',
        'frame_number',
        'horse_number',
        'sex',
        'age',
        'weight_carried',
        'horse_weight',
        'horse_weight_diff',
        'time',
        'time_seconds',
        'margin',
        'last_3f',
        'last_3f_seconds',
        'corner_positions',
        'running_style',
        'popularity',
        'win_odds',
        'place_odds_min',
        'place_odds_max',
        'prize_money',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'weight_carried' => 'decimal:1',
            'time_seconds' => 'decimal:2',
            'last_3f_seconds' => 'decimal:1',
            'win_odds' => 'decimal:1',
            'place_odds_min' => 'decimal:1',
            'place_odds_max' => 'decimal:1',
            'prize_money' => 'integer',
        ];
    }

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }

    public function jockey(): BelongsTo
    {
        return $this->belongsTo(Jockey::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    /**
     * 通過順位から脚質を自動判定
     */
    public static function detectRunningStyle(?string $cornerPositions, ?int $horsesCount): ?string
    {
        if (!$cornerPositions || !$horsesCount) {
            return null;
        }

        $positions = array_map('intval', preg_split('/[-]/', $cornerPositions));
        $positions = array_filter($positions, fn($v) => $v > 0);
        if (empty($positions)) {
            return null;
        }

        $firstCorner = (int) reset($positions);
        $ratio = $firstCorner / max($horsesCount, 1);

        return match (true) {
            $firstCorner === 1 => '逃',
            $ratio <= 0.3 => '先',
            $ratio <= 0.65 => '差',
            default => '追',
        };
    }
}
