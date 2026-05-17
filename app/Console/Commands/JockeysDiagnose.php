<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 騎手スコアが 0 になる原因を調べる診断コマンド
 *
 * 想定する原因と、それぞれの検出方法:
 *   A) jockeys テーブルに同名の重複行がある (name='岩田康誠' で id=100, id=850 など)
 *      → "同名複数 ID" として出力
 *   B) jockeys.name の表記ゆれ (国分優 vs 国分 優作 / フル vs 略称)
 *      → 名前を共通プレフィックス2文字で見ても同一人物だが文字列等価ではない
 *      → "近接同姓" として出力
 *   C) race_results.jockey_id が指している行が jockeys に存在しない (孤児)
 *      → "孤児行" として出力
 *   D) 出馬表の jockey_id について、race_results に過去走が 0 件
 *      → "過去走0件" として出力(指定レースのみ)
 *
 * 使い方:
 *   php artisan jockeys:diagnose                  # 全体サマリ
 *   php artisan jockeys:diagnose --race=12345     # 特定レースの出走騎手を 1 頭ずつ診断
 *   php artisan jockeys:diagnose --name=岩田康誠  # 名前で深掘り
 */
class JockeysDiagnose extends Command
{
    protected $signature = 'jockeys:diagnose
                            {--race= : 特定レース(races.id)の出走騎手を診断}
                            {--name= : 騎手名で深掘り(同名行・別表記候補・過去走数を表示)}';

    protected $description = '騎手スコアが 0 になる原因を診断する(同名重複/孤児/0件を検出)';

    public function handle(): int
    {
        $raceId = $this->option('race');
        $name   = $this->option('name');

        if ($raceId) {
            return $this->diagnoseRace((int) $raceId);
        }
        if ($name) {
            return $this->diagnoseName((string) $name);
        }
        return $this->diagnoseAll();
    }

    /** 全体サマリ */
    protected function diagnoseAll(): int
    {
        $this->info('=== jockeys テーブル全体サマリ ===');
        $total = DB::table('jockeys')->count();
        $withNk = DB::table('jockeys')->whereNotNull('netkeiba_id')->count();
        $woNk = $total - $withNk;
        $this->line("  総数:               {$total}");
        $this->line("  netkeiba_id あり:   {$withNk}");
        $this->line("  netkeiba_id なし:   {$woNk}");

        // 同名重複
        $dupNames = DB::table('jockeys')
            ->select('name', DB::raw('COUNT(*) as c'))
            ->whereNotNull('name')->where('name', '<>', '')
            ->groupBy('name')->having('c', '>', 1)
            ->orderByDesc('c')->limit(20)->get();
        $this->newLine();
        $this->info('=== 同名で複数行ある騎手 (上位20件) ===');
        if ($dupNames->isEmpty()) {
            $this->line('  なし');
        } else {
            foreach ($dupNames as $d) $this->line(sprintf('  %-12s × %d 行', $d->name, $d->c));
            $this->warn('  → php artisan jockeys:dedupe で統合できます');
        }

        // 過去走 0 件の jockey 数(出馬表/結果ともに)
        $allJockeyCount = DB::table('jockeys')->count();
        $jockeysWithResults = DB::table('race_results')
            ->whereNotNull('jockey_id')
            ->distinct()->count('jockey_id');
        $noResultCount = $allJockeyCount - $jockeysWithResults;
        $this->newLine();
        $this->info('=== race_results に 1 件も登場しない jockey 行 ===');
        $this->line("  対象行数: {$noResultCount} / {$allJockeyCount}");
        if ($noResultCount > 0) {
            $sample = DB::table('jockeys')
                ->leftJoin('race_results', 'race_results.jockey_id', '=', 'jockeys.id')
                ->select('jockeys.id', 'jockeys.name', 'jockeys.netkeiba_id')
                ->whereNull('race_results.id')
                ->limit(15)->get();
            foreach ($sample as $s) {
                $this->line(sprintf('  id=%d name=%-12s nk=%s', $s->id, $s->name, $s->netkeiba_id ?? 'null'));
            }
        }

        $this->newLine();
        $this->line('特定レースの出走騎手を 1 頭ずつ調べるには:');
        $this->line('  php artisan jockeys:diagnose --race=<races.id>');
        return self::SUCCESS;
    }

    /** レースごとの診断 */
    protected function diagnoseRace(int $raceId): int
    {
        $race = DB::table('races')->where('id', $raceId)->first();
        if (!$race) {
            $this->error("races.id={$raceId} が見つかりません");
            return self::FAILURE;
        }
        $this->info("=== レース #{$raceId} : {$race->name} ===");

        $rows = DB::table('race_results')
            ->leftJoin('jockeys', 'jockeys.id', '=', 'race_results.jockey_id')
            ->where('race_results.race_id', $raceId)
            ->orderBy('race_results.horse_number')
            ->select(
                'race_results.horse_number',
                'race_results.jockey_id',
                'jockeys.name as jockey_name',
                'jockeys.netkeiba_id as jockey_nk'
            )->get();

        foreach ($rows as $r) {
            $jid = $r->jockey_id;
            if (!$jid) {
                $this->line(sprintf('  %2d. (jockey_id 未設定)', $r->horse_number));
                continue;
            }

            // 1) この jockey_id 単体の過去走数
            $runsById = DB::table('race_results')
                ->where('jockey_id', $jid)
                ->whereNotNull('finish_position_int')
                ->count();

            // 2) 同名 jockey 全 ID の過去走数 (Round 9 マージで実際に使われる経路)
            $sameNameIds = DB::table('jockeys')->where('name', $r->jockey_name)->pluck('id')->all();
            $runsByName = DB::table('race_results')
                ->whereIn('jockey_id', $sameNameIds)
                ->whereNotNull('finish_position_int')
                ->count();

            // 3) 部分一致(名字 2 文字)で別表記の候補
            $surname = $r->jockey_name ? mb_substr($r->jockey_name, 0, 2) : '';
            $similarRows = $surname !== ''
                ? DB::table('jockeys')
                    ->where('name', 'like', $surname . '%')
                    ->where('name', '<>', $r->jockey_name)
                    ->limit(5)->get()
                : collect();

            $tag = $runsByName > 0 ? 'OK' : 'NG';
            $this->line(sprintf(
                '  %2d. jockey_id=%d  name=%s (nk=%s) | byId=%d, byName=%d [%s]',
                $r->horse_number,
                $jid,
                $r->jockey_name ?? '?',
                $r->jockey_nk ?? 'null',
                $runsById,
                $runsByName,
                $tag
            ));
            if ($runsByName === 0 && $similarRows->isNotEmpty()) {
                foreach ($similarRows as $s) {
                    $cnt = DB::table('race_results')->where('jockey_id', $s->id)
                        ->whereNotNull('finish_position_int')->count();
                    $this->line(sprintf(
                        '       ↳ 別表記候補? id=%d name=%-12s nk=%s runs=%d',
                        $s->id, $s->name, $s->netkeiba_id ?? 'null', $cnt
                    ));
                }
            }
        }
        return self::SUCCESS;
    }

    /** 名前指定の深掘り */
    protected function diagnoseName(string $name): int
    {
        $this->info("=== 騎手名 '{$name}' の深掘り ===");
        $exact = DB::table('jockeys')->where('name', $name)->get();
        $this->line("  完全一致行数: {$exact->count()}");
        foreach ($exact as $r) {
            $runs = DB::table('race_results')->where('jockey_id', $r->id)
                ->whereNotNull('finish_position_int')->count();
            $this->line(sprintf('    id=%d nk=%s runs=%d', $r->id, $r->netkeiba_id ?? 'null', $runs));
        }
        $surname = mb_substr($name, 0, 2);
        $similar = DB::table('jockeys')
            ->where('name', 'like', $surname . '%')
            ->where('name', '<>', $name)
            ->get();
        if ($similar->isNotEmpty()) {
            $this->newLine();
            $this->info("  名字一致 '{$surname}%' の別表記候補:");
            foreach ($similar as $r) {
                $runs = DB::table('race_results')->where('jockey_id', $r->id)
                    ->whereNotNull('finish_position_int')->count();
                $this->line(sprintf('    id=%d name=%-12s nk=%s runs=%d',
                    $r->id, $r->name, $r->netkeiba_id ?? 'null', $runs));
            }
        }
        return self::SUCCESS;
    }
}
