<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 同名で複数行できてしまった jockeys レコードを統合する保守コマンド
 *
 * 背景:
 *   過去レース取込は `netkeiba_id` 優先で `Jockey::firstOrCreate(['netkeiba_id'=>...])` し、
 *   出馬表取込は `netkeiba_id` 無しで `Jockey::firstOrCreate(['name'=>...])` するため、
 *   同一人物が複数の jockeys.id を持ち、出馬表側 ID では過去成績が引けない事象が発生する。
 *
 * 動作:
 *   1) 同じ name を持つ jockeys 行をグルーピング
 *   2) netkeiba_id が埋まっている行を「正」、無い行を「副」とみなす
 *      (両方とも netkeiba_id なし、または両方ともある場合は id の小さい方を正とする)
 *   3) race_results.jockey_id を「副 → 正」に書き換え
 *   4) 副行を削除
 *
 * 使い方:
 *   php artisan jockeys:dedupe --dry-run    # 影響範囲のみ表示
 *   php artisan jockeys:dedupe              # 実際に統合する
 */
class JockeysDedupe extends Command
{
    protected $signature = 'jockeys:dedupe
                            {--dry-run : 実際の更新は行わず、対象件数のみ表示}
                            {--prefix-merge : 前方一致でも統合する(例: 丹内 ⇔ 丹内祐次)}';

    protected $description = '同名/前方一致で重複している jockeys 行を統合し、race_results.jockey_id を付け替える';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $prefixMerge = (bool) $this->option('prefix-merge');

        $this->info('=== jockeys 重複検出 ===');
        // name でグルーピングして、行数 > 1 の name を抽出
        $dupNames = DB::table('jockeys')
            ->select('name', DB::raw('COUNT(*) as c'))
            ->whereNotNull('name')
            ->where('name', '<>', '')
            ->groupBy('name')
            ->having('c', '>', 1)
            ->pluck('c', 'name');

        if ($dupNames->isEmpty()) {
            $this->info('重複なし');
            return self::SUCCESS;
        }

        $this->line(sprintf('  重複している騎手名: %d 件', $dupNames->count()));

        $totalMerged   = 0;
        $totalUpdated  = 0;
        $totalDeleted  = 0;

        foreach ($dupNames as $name => $cnt) {
            $rows = DB::table('jockeys')->where('name', $name)->orderBy('id', 'asc')->get();
            if ($rows->count() < 2) continue;

            // 正(残す)を決める: netkeiba_id 有りを優先、複数あれば id 最小
            $withNk = $rows->filter(fn($r) => !empty($r->netkeiba_id))->values();
            if ($withNk->isNotEmpty()) {
                $keep = $withNk->first();
            } else {
                $keep = $rows->first();
            }
            $losers = $rows->filter(fn($r) => $r->id !== $keep->id)->values();
            if ($losers->isEmpty()) continue;

            $loserIds = $losers->pluck('id')->all();

            // 影響する race_results 件数
            $hitCount = DB::table('race_results')->whereIn('jockey_id', $loserIds)->count();

            $this->line(sprintf(
                '  - 騎手 "%s": keep id=%d (nk=%s), merge ids=%s, race_results 影響=%d',
                $name,
                $keep->id,
                $keep->netkeiba_id ?? 'null',
                implode(',', $loserIds),
                $hitCount
            ));

            if ($dry) {
                $totalMerged  += count($loserIds);
                $totalUpdated += $hitCount;
                $totalDeleted += count($loserIds);
                continue;
            }

            DB::transaction(function () use ($keep, $loserIds, &$totalUpdated, &$totalDeleted, &$totalMerged) {
                // race_results.jockey_id を付け替え
                $updated = DB::table('race_results')
                    ->whereIn('jockey_id', $loserIds)
                    ->update(['jockey_id' => $keep->id]);
                $totalUpdated += (int) $updated;

                // 出馬表(shutuba_entries / shutuba_horses など)で jockey_id を持つテーブルも合わせて更新
                foreach (['shutuba_entries', 'shutuba_horses'] as $tbl) {
                    if (!\Illuminate\Support\Facades\Schema::hasTable($tbl)) continue;
                    if (!\Illuminate\Support\Facades\Schema::hasColumn($tbl, 'jockey_id')) continue;
                    DB::table($tbl)->whereIn('jockey_id', $loserIds)->update(['jockey_id' => $keep->id]);
                }

                // 副行を削除
                $deleted = DB::table('jockeys')->whereIn('id', $loserIds)->delete();
                $totalDeleted += (int) $deleted;
                $totalMerged  += count($loserIds);
            });
        }

        $this->newLine();
        $this->info(sprintf(
            '%s [同名統合] %d 行を統合, race_results.jockey_id を %d 行更新, jockeys から %d 行削除',
            $dry ? '[DRY-RUN]' : '完了',
            $totalMerged,
            $totalUpdated,
            $totalDeleted
        ));

        // ====================================================================
        // 前方一致統合 (--prefix-merge オプション)
        // 例: '丹内' (nk=01091, runs=0) ← '丹内祐次' (nk=null, runs=6065)
        //   → '丹内' 側を残し、'丹内祐次' の過去走を '丹内' に付け替える
        //   ※ name は『netkeiba_id を持つ方=出馬表側』を残す。これにより
        //     今後の出馬表取込みで自然に同じ id が使われる。
        // ====================================================================
        if ($prefixMerge) {
            $this->newLine();
            $this->info('=== 前方一致による統合 (--prefix-merge) ===');
            $pMerged   = 0;
            $pUpdated  = 0;
            $pDeleted  = 0;

            // netkeiba_id 付きで name 末尾に空白を含まない短い名前 (=出馬表由来の省略名候補)
            $shortNamed = DB::table('jockeys')
                ->whereNotNull('netkeiba_id')
                ->whereRaw('CHAR_LENGTH(name) <= 9')  // 漢字3文字以内程度を想定 (UTF-8 で 9bytes 以内)
                ->select('id', 'name', 'netkeiba_id')
                ->get();

            foreach ($shortNamed as $keep) {
                $cleanKeep = preg_replace('/^[▲☆△◇○◎\*]+/u', '', $keep->name);
                $cleanKeep = preg_replace('/[\s\x{3000}]+/u', '', $cleanKeep);
                $hasMark   = preg_match('/^[▲☆△◇○◎\*]/u', $keep->name);
                if ($hasMark && mb_strlen($cleanKeep) <= 2) continue;  // 印付き苗字のみは危険
                if (mb_strlen($cleanKeep) < 2) continue;

                // 前方一致候補: netkeiba_id null、name LIKE 'cleanKeep%'、過去走を持つ
                $cands = DB::table('jockeys as j')
                    ->leftJoin('race_results as r', 'r.jockey_id', '=', 'j.id')
                    ->whereNull('j.netkeiba_id')
                    ->where('j.id', '!=', $keep->id)
                    ->where(function ($q) use ($cleanKeep) {
                        $q->where('j.name', 'like', $cleanKeep . '%')
                          ->orWhereRaw("REPLACE(REPLACE(j.name,' ',''),'　','') LIKE ?", [$cleanKeep . '%']);
                    })
                    ->groupBy('j.id', 'j.name')
                    ->select('j.id', 'j.name', DB::raw('COUNT(r.id) as runs'))
                    ->havingRaw('COUNT(r.id) > 0')
                    ->orderByDesc('runs')
                    ->get();

                if ($cands->isEmpty()) continue;

                // 最も過去走の多い 1 件のみ統合 (同姓他人の誤マージ防止)
                $loser = $cands->first();
                $this->line(sprintf(
                    '  - "%s" (id=%d, nk=%s) ← "%s" (id=%d, runs=%d)',
                    $keep->name, $keep->id, $keep->netkeiba_id,
                    $loser->name, $loser->id, $loser->runs
                ));
                if ($dry) { $pMerged++; $pUpdated += (int)$loser->runs; $pDeleted++; continue; }

                DB::transaction(function () use ($keep, $loser, &$pMerged, &$pUpdated, &$pDeleted) {
                    $u = DB::table('race_results')->where('jockey_id', $loser->id)
                        ->update(['jockey_id' => $keep->id]);
                    $pUpdated += (int) $u;
                    foreach (['shutuba_entries', 'shutuba_horses'] as $tbl) {
                        if (!\Illuminate\Support\Facades\Schema::hasTable($tbl)) continue;
                        if (!\Illuminate\Support\Facades\Schema::hasColumn($tbl, 'jockey_id')) continue;
                        DB::table($tbl)->where('jockey_id', $loser->id)->update(['jockey_id' => $keep->id]);
                    }
                    DB::table('jockeys')->where('id', $loser->id)->delete();
                    $pDeleted++;
                    $pMerged++;
                });
            }

            $this->newLine();
            $this->info(sprintf(
                '%s [前方一致統合] %d 件統合, race_results を %d 行更新, jockeys から %d 行削除',
                $dry ? '[DRY-RUN]' : '完了',
                $pMerged, $pUpdated, $pDeleted
            ));
        } else {
            $this->newLine();
            $this->line('ヒント: 略称↔フルネームの統合は --prefix-merge を付けて再実行してください');
            $this->line('       例: php artisan jockeys:dedupe --prefix-merge --dry-run');
        }

        if (!$dry) {
            $this->info('※ キャッシュをクリアしてください: php artisan cache:clear');
        }

        return self::SUCCESS;
    }
}
