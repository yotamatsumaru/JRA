<?php

namespace App\Services;

use App\Models\OddsSnapshot;
use App\Models\Race;

/**
 * オッズスナップショット保存サービス (Phase 3-I)
 *  - NetkeibaScraper::fetchShutuba() の結果から win_odds / popularity を抽出
 *  - odds_snapshots テーブルへ時系列保存
 */
class OddsSnapshotService
{
    public function __construct(protected NetkeibaScraper $scraper)
    {
    }

    /**
     * レースのオッズを取得して保存
     *
     * @return OddsSnapshot|null  保存できなかった (発走後など) は null
     */
    public function captureForRace(Race $race): ?OddsSnapshot
    {
        if (empty($race->netkeiba_id)) {
            return null;
        }

        try {
            $shutuba = $this->scraper->fetchShutuba($race->netkeiba_id);
        } catch (\Throwable $e) {
            \Log::info("OddsSnapshot fetch failed: race#{$race->id}: " . $e->getMessage());
            return null;
        }

        $rows = $shutuba['results'] ?? $shutuba['rows'] ?? $shutuba['horses'] ?? [];
        if (empty($rows)) return null;

        $payload = [];
        foreach ($rows as $row) {
            $hno = (int) ($row['horse_number'] ?? 0);
            if ($hno < 1) continue;
            $payload[$hno] = [
                'horse_name' => $row['horse_name'] ?? null,
                'win_odds'   => isset($row['win_odds']) ? (float) $row['win_odds'] : null,
                'popularity' => isset($row['popularity']) ? (int) $row['popularity'] : null,
            ];
        }

        if (empty($payload)) return null;

        return OddsSnapshot::create([
            'race_id'     => $race->id,
            'captured_at' => now(),
            'source'      => 'netkeiba',
            'payload'     => $payload,
        ]);
    }

    /**
     * 出走前のレースを対象に一括スナップショット
     *
     * @param  int $minutesBefore レース発走何分前までを対象にするか
     * @param  int $limit         一度に処理する最大件数
     */
    public function captureUpcoming(int $minutesBefore = 30, int $limit = 50): array
    {
        $now = now();
        $races = Race::whereNotNull('netkeiba_id')
            ->whereDate('race_date', $now->toDateString())
            ->whereDoesntHave('results', fn($q) => $q->whereNotNull('finish_position_int'))
            ->orderBy('race_number')
            ->limit($limit)
            ->get();

        $captured = 0; $skipped = 0; $errors = 0;
        foreach ($races as $race) {
            try {
                $snap = $this->captureForRace($race);
                $snap ? $captured++ : $skipped++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        return ['captured' => $captured, 'skipped' => $skipped, 'errors' => $errors, 'total' => $races->count()];
    }

    /**
     * レースのオッズ推移を取得 (グラフ用)
     */
    public function timeline(int $raceId): array
    {
        $snaps = OddsSnapshot::where('race_id', $raceId)
            ->orderBy('captured_at')
            ->get();

        $series = [];
        foreach ($snaps as $s) {
            $ts = $s->captured_at->format('H:i');
            foreach (($s->payload ?? []) as $hno => $row) {
                $series[$hno][] = [
                    't'    => $ts,
                    'odds' => $row['win_odds'] ?? null,
                    'pop'  => $row['popularity'] ?? null,
                ];
            }
        }
        return $series;
    }
}
