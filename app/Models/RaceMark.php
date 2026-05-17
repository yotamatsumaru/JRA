<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 出馬表の印・メモ・スコアキャッシュ
 *
 * 1ユーザー × 1出走馬(race_result) で1レコード。
 *
 * @property int $id
 * @property int $user_id
 * @property int $race_id
 * @property int $race_result_id
 * @property string|null $mark        ◎○▲△☆✕ または NULL
 * @property string|null $memo
 * @property float|null  $score_total
 * @property float|null  $score_pedigree
 * @property float|null  $score_jockey
 * @property float|null  $score_horse
 * @property float|null  $score_roi
 * @property float|null  $score_frame
 * @property float|null  $score_course
 * @property float|null  $score_style
 * @property \Illuminate\Support\Carbon|null $scored_at
 */
class RaceMark extends Model
{
    use HasFactory;

    /** 利用可能な印（順序あり） */
    public const MARKS = ['◎', '○', '▲', '△', '☆', '✕'];

    /** 印ごとのスタイル(badge色) */
    public const MARK_COLORS = [
        '◎' => 'bg-red-100 text-red-700 border-red-300',
        '○' => 'bg-blue-100 text-blue-700 border-blue-300',
        '▲' => 'bg-amber-100 text-amber-700 border-amber-300',
        '△' => 'bg-emerald-100 text-emerald-700 border-emerald-300',
        '☆' => 'bg-purple-100 text-purple-700 border-purple-300',
        '✕' => 'bg-gray-200 text-gray-500 border-gray-300',
    ];

    protected $fillable = [
        'user_id',
        'race_id',
        'race_result_id',
        'mark',
        'memo',
        'score_total',
        'score_pedigree',
        'score_jockey',
        'score_horse',
        'score_roi',
        'score_frame',
        'score_course',
        'score_style',
        'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'score_total'    => 'decimal:2',
            'score_pedigree' => 'decimal:2',
            'score_jockey'   => 'decimal:2',
            'score_horse'    => 'decimal:2',
            'score_roi'      => 'decimal:2',
            'score_frame'    => 'decimal:2',
            'score_course'   => 'decimal:2',
            'score_style'    => 'decimal:2',
            'scored_at'      => 'datetime',
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

    public function raceResult(): BelongsTo
    {
        return $this->belongsTo(RaceResult::class);
    }
}
