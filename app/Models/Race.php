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

    public function marks(): HasMany
    {
        return $this->hasMany(RaceMark::class);
    }

    /**
     * 指定ユーザーの印一覧 (race_result_id => mark)
     */
    public function marksFor(int $userId): array
    {
        return $this->marks()
            ->where('user_id', $userId)
            ->pluck('mark', 'race_result_id')
            ->toArray();
    }

    /**
     * 出馬表のみ(レース未確定)かどうか
     */
    public function getIsShutubaOnlyAttribute(): bool
    {
        return $this->results()->whereNotNull('finish_position_int')->doesntExist()
            && $this->results()->exists();
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
     * ペース(H/M/S)を判定 (案2: 前後半3Fラップ差ベース、フォールバックは通過順)
     *
     * 主判定: races.first_3f と races.last_3f の差
     *   - first_3f が last_3f より 0.6 秒以上速い → 'H' (ハイ)
     *   - first_3f が last_3f より 0.6 秒以上遅い → 'S' (スロー)
     *   - それ以外                                → 'M' (ミドル)
     *   ※ JRA 公式の前後半3F差判定に近い基準
     *
     * フォールバック (ラップ未取得時): 通過順から推定。
     *   1コーナーで「前 1/4 以内」に何頭居たかの密度で判定する。
     *   - density >= 0.90 (前1/4枠がほぼ埋まる) → 'H' (先行集団が密集 = ハイ)
     *   - density <= 0.55 (前1/4枠がスカスカ)   → 'S' (前が手薄 = スロー)
     *   - それ以外                              → 'M'
     *
     *   ※ 旧ロジックは「前1/3以内/全頭」という分母の取り方が原因で
     *     leadRatio が常に約 1/3 周辺になり 'S' が構造的に出ない欠陥があった。
     *
     * @param iterable        $results       RaceResult のコレクション。corner_positions が望ましい。
     * @param int|null        $horsesCount   出走頭数(レコード数より優先)
     * @param string|int|null $firstHalf3f   前半3F (秒, "34.5" など) — Race::first_3f
     * @param string|int|null $lastHalf3f    後半3F (秒, "35.1" など) — Race::last_3f
     * @return string|null  'H' | 'M' | 'S' | null
     */
    public static function detectPace(
        iterable $results,
        ?int $horsesCount = null,
        $firstHalf3f = null,
        $lastHalf3f = null
    ): ?string {
        // ===== 主判定: 前後半3Fラップ差 =====
        $first = self::parseLapSeconds($firstHalf3f);
        $last  = self::parseLapSeconds($lastHalf3f);
        if ($first !== null && $last !== null) {
            $diff = $first - $last; // + なら前半が遅い、- なら前半が速い
            if ($diff <= -0.6) return 'H'; // 前半の方が0.6秒以上速い → ハイ
            if ($diff >=  0.6) return 'S'; // 前半の方が0.6秒以上遅い → スロー
            return 'M';
        }

        // ===== フォールバック: 通過順から推定 =====
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

        // 1コーナーで前 1/4 以内に居た馬の頭数
        $threshold = max(2, (int) ceil($hc / 4));
        $leadCount = 0;
        foreach ($firsts as $p) {
            if ($p <= $threshold) $leadCount++;
        }
        // 「前1/4枠の埋まり具合」 (分母を $threshold にすることで構造バイアスを排除)
        $density = $leadCount / $threshold;

        return match (true) {
            $density >= 0.90 => 'H',
            $density <= 0.55 => 'S',
            default          => 'M',
        };
    }

    /**
     * "34.5" / "0:34.5" / 34.5 などのラップ表記を秒(float)に変換
     */
    protected static function parseLapSeconds($v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) {
            $f = (float) $v;
            return ($f > 0 && $f < 120) ? $f : null;
        }
        $s = trim((string) $v);
        if ($s === '') return null;
        // "M:SS.s" 形式
        if (preg_match('/^(\d+):(\d{1,2}(?:\.\d+)?)$/', $s, $m)) {
            $sec = (int) $m[1] * 60 + (float) $m[2];
            return ($sec > 0 && $sec < 120) ? $sec : null;
        }
        // "34.5" / "34" など
        if (preg_match('/^\d+(?:\.\d+)?$/', $s)) {
            $sec = (float) $s;
            return ($sec > 0 && $sec < 120) ? $sec : null;
        }
        return null;
    }
}
