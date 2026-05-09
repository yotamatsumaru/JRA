<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * レース
 *
 * @property int $id
 * @property string|null $netkeiba_id
 * @property int $venue_id
 * @property \Illuminate\Support\Carbon $race_date
 * @property int $race_number
 * @property string $name
 * @property string|null $grade
 * @property string $track_type
 * @property int $distance
 * @property string|null $course_condition
 */
class Race extends Model
{
    use HasFactory;

    protected $fillable = [
        'netkeiba_id',
        'venue_id',
        'race_date',
        'kaisai_kai',
        'kaisai_day',
        'race_number',
        'name',
        'grade',
        'race_class',
        'track_type',
        'distance',
        'direction',
        'course_detail',
        'course_condition',
        'weather',
        'pace',
        'lap_times',
        'first_3f',
        'last_3f',
        'horses_count',
        'first_prize',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'race_date' => 'date',
            'lap_times' => 'array',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(RaceResult::class)->orderBy('finish_position_int');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(RaceNote::class);
    }

    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function winner(): ?RaceResult
    {
        return $this->results()->where('finish_position_int', 1)->first();
    }

    public function getDistanceCategoryAttribute(): string
    {
        return match (true) {
            $this->distance <= 1400 => '短距離',
            $this->distance <= 1800 => 'マイル',
            $this->distance <= 2200 => '中距離',
            $this->distance <= 2600 => '中長距離',
            default => '長距離',
        };
    }

    public function getFullNameAttribute(): string
    {
        $venue = $this->venue?->name ?? '';
        return sprintf(
            '%s %sR %s (%s%dm)',
            $venue,
            $this->race_number,
            $this->name,
            $this->track_type,
            $this->distance
        );
    }

    /**
     * レース結果の通過順位からペース(H/M/S)を判定
     *
     * 判定ロジック:
     *   1コーナー時点で出走頭数の前 1/3 (=逃げ・先行) に居た馬の数を集計し、
     *   逃げ/先行の "団子度合い" でハイ/ミドル/スローを推定する。
     *
     *   - 先行馬が多い (頭数の40%超) → ハイペース ('H')
     *   - 普通                       → ミドル   ('M')
     *   - 先行馬が少ない (頭数の20%未満) → スロー ('S')
     *
     * 通過順データがそろっていない場合は null。
     *
     * @param iterable $results  RaceResult のコレクション。corner_positions が必須。
     * @param int|null $horsesCount  出走頭数(レコード数より優先)
     * @return string|null  'H' | 'M' | 'S' | null
     */
    public static function detectPace(iterable $results, ?int $horsesCount = null): ?string
    {
        $firsts = [];
        foreach ($results as $r) {
            $corner = is_object($r) ? ($r->corner_positions ?? null) : ($r['corner_positions'] ?? null);
            if (!$corner) continue;
            $parts = preg_split('/[-]/', $corner);
            $firstCorner = (int) ($parts[0] ?? 0);
            if ($firstCorner > 0) {
                $firsts[] = $firstCorner;
            }
        }
        if (empty($firsts)) return null;

        $hc = $horsesCount ?: count($firsts);
        if ($hc < 6) return null; // 少頭数はペース判定の意味が薄い

        // 1コーナーで前 1/3 に居た馬の頭数
        $threshold = max(2, (int) ceil($hc / 3));
        $leadCount = 0;
        foreach ($firsts as $p) {
            if ($p <= $threshold) $leadCount++;
        }

        $leadRatio = $leadCount / $hc;
        return match (true) {
            $leadRatio >= 0.40 => 'H',
            $leadRatio <  0.20 => 'S',
            default            => 'M',
        };
    }
}
