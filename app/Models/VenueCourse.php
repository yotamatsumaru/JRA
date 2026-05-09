<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 競馬場 × トラック種別 × 距離 のコース情報
 *
 * @property int         $id
 * @property int         $venue_id
 * @property string      $track_type        芝 / ダート / 障害
 * @property int         $distance          距離(m)
 * @property string|null $course_variation  A/B/C/D 等
 * @property int|null    $straight_length   最後の直線長(m)
 * @property float|null  $elevation_diff    高低差(m)
 * @property int|null    $corner_count      コーナー数
 * @property string|null $start_position    スタート位置
 * @property string|null $favored_style     有利脚質
 * @property string|null $favored_frame     有利枠
 * @property string|null $pace_tendency     ペース傾向
 * @property string|null $notes             特徴コメント
 */
class VenueCourse extends Model
{
    use HasFactory;

    protected $fillable = [
        'venue_id',
        'track_type',
        'distance',
        'course_variation',
        'straight_length',
        'elevation_diff',
        'corner_count',
        'start_position',
        'favored_style',
        'favored_frame',
        'pace_tendency',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'distance'        => 'integer',
            'straight_length' => 'integer',
            'elevation_diff'  => 'float',
            'corner_count'    => 'integer',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
