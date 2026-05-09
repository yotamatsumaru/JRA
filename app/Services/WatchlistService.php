<?php

namespace App\Services;

use App\Models\Race;
use App\Models\RaceResult;
use App\Models\Watchlist;
use Illuminate\Support\Carbon;

/**
 * ウォッチリストサービス (Phase 4-W)
 *
 *  - 登録された 馬/騎手/厩舎 の出走予定 (今日〜N日先) を取得
 *  - 直近成績サマリ (5走) を作成
 */
class WatchlistService
{
    /**
     * ユーザーのウォッチリストを target ごとに整理して返す
     *
     * @return array{horses: \Illuminate\Support\Collection, jockeys: \Illuminate\Support\Collection, trainers: \Illuminate\Support\Collection}
     */
    public function listFor(int $userId): array
    {
        $items = Watchlist::where('user_id', $userId)->orderBy('id')->get();

        return [
            'horses'   => $items->where('target_type', 'horse')->values(),
            'jockeys'  => $items->where('target_type', 'jockey')->values(),
            'trainers' => $items->where('target_type', 'trainer')->values(),
        ];
    }

    /**
     * 今日〜N日後までの出走予定でウォッチ対象が含まれるレース一覧
     *
     * @return array<int, array{race: Race, hits: array}>
     */
    public function upcomingEntries(int $userId, int $days = 7): array
    {
        $watchlist = Watchlist::where('user_id', $userId)->get();
        if ($watchlist->isEmpty()) return [];

        $horseIds   = $watchlist->where('target_type', 'horse')->pluck('target_id')->map(fn($v) => (int) $v)->all();
        $jockeyIds  = $watchlist->where('target_type', 'jockey')->pluck('target_id')->map(fn($v) => (int) $v)->all();
        $trainerIds = $watchlist->where('target_type', 'trainer')->pluck('target_id')->map(fn($v) => (int) $v)->all();

        // 全部空 (理論上は isEmpty で弾かれるが防御)
        if (empty($horseIds) && empty($jockeyIds) && empty($trainerIds)) return [];

        $today = Carbon::today();
        $end   = $today->copy()->addDays($days);

        $rrQuery = RaceResult::query()
            ->with(['horse:id,name', 'jockey:id,name', 'trainer:id,name', 'race:id,name,venue_id,race_date,race_number,track_type,distance', 'race.venue:id,name'])
            ->whereHas('race', fn($q) => $q->whereBetween('race_date', [$today->toDateString(), $end->toDateString()]))
            ->where(function ($q) use ($horseIds, $jockeyIds, $trainerIds) {
                // 各 IN 条件を独立した OR グループとしてまとめる (空配列はスキップ)
                $applied = false;
                if (!empty($horseIds)) {
                    $q->orWhereIn('horse_id', $horseIds);
                    $applied = true;
                }
                if (!empty($jockeyIds)) {
                    $q->orWhereIn('jockey_id', $jockeyIds);
                    $applied = true;
                }
                if (!empty($trainerIds)) {
                    $q->orWhereIn('trainer_id', $trainerIds);
                    $applied = true;
                }
                // 全て空の場合は対象なしになるよう raw 0
                if (!$applied) {
                    $q->whereRaw('0 = 1');
                }
            });

        $results = $rrQuery->get();

        // race ごとに集約
        $grouped = [];
        foreach ($results as $rr) {
            $rid = $rr->race_id;
            if (!isset($grouped[$rid])) {
                $grouped[$rid] = ['race' => $rr->race, 'hits' => []];
            }
            $hits = [];
            if (in_array((int) $rr->horse_id, $horseIds, true)) {
                $hits[] = ['type' => 'horse',   'name' => $rr->horse?->name   ?? '#'.$rr->horse_id,   'horse_no' => $rr->horse_number];
            }
            if (in_array((int) $rr->jockey_id, $jockeyIds, true)) {
                $hits[] = ['type' => 'jockey',  'name' => $rr->jockey?->name  ?? '#'.$rr->jockey_id,  'horse_no' => $rr->horse_number];
            }
            if (in_array((int) $rr->trainer_id, $trainerIds, true)) {
                $hits[] = ['type' => 'trainer', 'name' => $rr->trainer?->name ?? '#'.$rr->trainer_id, 'horse_no' => $rr->horse_number];
            }
            $grouped[$rid]['hits'] = array_merge($grouped[$rid]['hits'], $hits);
        }

        // 日付順ソート
        usort($grouped, function ($a, $b) {
            $da = $a['race']?->race_date?->timestamp ?? 0;
            $db = $b['race']?->race_date?->timestamp ?? 0;
            if ($da !== $db) return $da <=> $db;
            return ($a['race']?->race_number ?? 0) <=> ($b['race']?->race_number ?? 0);
        });

        return array_values($grouped);
    }

    /**
     * ターゲット (馬/騎手/厩舎) の直近 N 走を取得
     */
    public function recentForTarget(string $type, int $targetId, int $limit = 5): array
    {
        $col = match ($type) {
            'horse'   => 'horse_id',
            'jockey'  => 'jockey_id',
            'trainer' => 'trainer_id',
            default   => null,
        };
        if (!$col) return [];

        return RaceResult::with(['race.venue', 'horse', 'jockey'])
            ->where($col, $targetId)
            ->whereNotNull('finish_position_int')
            ->whereHas('race', fn($q) => $q->orderByDesc('race_date'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
