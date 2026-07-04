<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\NetkeibaScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * DB の race_date が netkeiba 公式カレンダーと一致しているかを診断する
 *
 * 目的:
 *   出馬表取込などで race_date に誤日付が入るケース（例: 日本ダービー id=27453 が
 *   2026-07-04 に登録されていた）を、netkeiba のカレンダー(/race/sum/YYYYMM/) を
 *   正として自動検出する。必要に応じて --fix で一括補正まで実行。
 *
 * 動作:
 *   1. 対象 Race レコードを列挙 (netkeiba_id 12桁必須)
 *   2. netkeiba_id の先頭6桁(YYYYMM) ごとに netkeiba カレンダーを取得
 *   3. 「その月に netkeiba が返す各開催日」から race_id 一覧を取得
 *   4. race_id → kaisai_date のマップを構築
 *   5. DB race_date と付き合わせ、不一致なら報告 (--fix 指定時のみ更新)
 *
 * 使い方:
 *   # 今年のレース全件を診断（読み取りのみ）
 *   php artisan netkeiba:diagnose-dates
 *
 *   # 2026年だけ
 *   php artisan netkeiba:diagnose-dates --year=2026
 *
 *   # 2026年6月だけ
 *   php artisan netkeiba:diagnose-dates --year=2026 --month=6
 *
 *   # 検出した不整合を自動補正
 *   php artisan netkeiba:diagnose-dates --year=2026 --fix
 *
 *   # --fix と併用して、実際には更新せず対象だけ表示
 *   php artisan netkeiba:diagnose-dates --year=2026 --fix --dry-run
 *
 *   # スクレイピング負荷を抑えたい時はサンプル件数を制限
 *   php artisan netkeiba:diagnose-dates --year=2026 --limit=200
 */
class NetkeibaDiagnoseDates extends Command
{
    protected $signature = 'netkeiba:diagnose-dates
        {--year= : 対象年 (YYYY)。省略時は今年}
        {--month= : 対象月 (1-12)。指定するとその月のみ}
        {--fix : 検出した不整合を自動補正 (デフォルトは検出のみ)}
        {--dry-run : --fix 併用時、実行内容だけ表示}
        {--limit=0 : 診断対象レース件数の上限 (0=無制限)}
        {--only-future : 今日以降のレースのみ (取込直後の検査向け)}';

    protected $description = 'DB の race_date が netkeiba 公式カレンダーと一致するかを診断（不一致検出＆自動補正）';

    public function handle(NetkeibaScraper $scraper): int
    {
        // ------------ オプション整理 ------------
        $year = $this->option('year');
        if ($year === null || $year === '') {
            $year = (int) date('Y');
        } else {
            if (!preg_match('/^\d{4}$/', (string) $year)) {
                $this->error('--year は YYYY 形式で指定してください');
                return self::FAILURE;
            }
            $year = (int) $year;
        }

        $month = $this->option('month');
        if ($month !== null && $month !== '') {
            if (!ctype_digit((string) $month) || (int) $month < 1 || (int) $month > 12) {
                $this->error('--month は 1-12 の整数で指定してください');
                return self::FAILURE;
            }
            $month = (int) $month;
        } else {
            $month = null;
        }

        $doFix   = (bool) $this->option('fix');
        $dryRun  = (bool) $this->option('dry-run');
        $limit   = (int) $this->option('limit');
        $onlyFut = (bool) $this->option('only-future');

        // ------------ 対象 Race を選択 ------------
        $q = Race::query()
            ->whereNotNull('netkeiba_id')
            ->where('netkeiba_id', 'REGEXP', '^[0-9]{12}$');

        // 年フィルタ (netkeiba_id 先頭4桁)
        $q->where('netkeiba_id', 'like', sprintf('%04d', $year) . '%');

        // 月フィルタ (netkeiba_id 先頭6桁は「西暦年+場コード」でありYYYYMMではない)
        //   → 月絞り込みは race_date 側で行う (race_date が null なら通す)
        if ($month !== null) {
            $q->where(function ($qq) use ($year, $month) {
                $qq->whereBetween('race_date', [
                        sprintf('%04d-%02d-01', $year, $month),
                        date('Y-m-t', mktime(0, 0, 0, $month, 1, $year)),
                    ])
                  ->orWhereNull('race_date');
            });
        }

        if ($onlyFut) {
            $q->where(function ($qq) {
                $qq->whereDate('race_date', '>=', now()->toDateString())
                   ->orWhereNull('race_date');
            });
        }

        $q->orderBy('netkeiba_id');

        if ($limit > 0) {
            $q->limit($limit);
        }

        $total = (clone $q)->count();
        $this->info(sprintf(
            '診断対象: %d 件 (year=%d%s%s%s)',
            $total,
            $year,
            $month !== null ? ", month={$month}" : '',
            $onlyFut ? ', only-future' : '',
            $limit > 0 ? ", limit={$limit}" : ''
        ));
        if ($total === 0) {
            $this->warn('対象なし');
            return self::SUCCESS;
        }

        // ------------ 対象月 (YYYY-MM) を洗い出す ------------
        //  netkeiba_id からは月が読めないので、DB race_date か
        //  --month 指定を元に「照会する月リスト」を作る
        $monthKeys = [];
        if ($month !== null) {
            $monthKeys[sprintf('%04d-%02d', $year, $month)] = true;
        } else {
            // 対象 Race の race_date から year の月を集める
            $months = (clone $q)
                ->select('race_date')
                ->whereNotNull('race_date')
                ->get()
                ->map(fn ($r) => optional($r->race_date)->format('Y-m'))
                ->filter()
                ->unique()
                ->values();
            foreach ($months as $ym) {
                $monthKeys[$ym] = true;
            }
            // 月が全く取れなければ年内12ヶ月を全部見る (保守的)
            if (empty($monthKeys)) {
                for ($m = 1; $m <= 12; $m++) {
                    $monthKeys[sprintf('%04d-%02d', $year, $m)] = true;
                }
            }
        }
        $monthKeys = array_keys($monthKeys);
        sort($monthKeys);
        $this->line('照会月: ' . implode(', ', $monthKeys));

        // ------------ 各月の netkeiba カレンダーを取得 ------------
        // $officialByRid = ['202605021211' => '2026-06-07', ...]
        $officialByRid = [];
        foreach ($monthKeys as $ym) {
            [$yy, $mm] = array_map('intval', explode('-', $ym));
            $this->line("  [{$ym}] netkeiba カレンダー取得中…");
            try {
                $dates = $scraper->fetchOpenDatesByMonth($yy, $mm);
            } catch (\Throwable $e) {
                $this->warn("  [{$ym}] カレンダー取得失敗: " . $e->getMessage());
                Log::warning("diagnose-dates: calendar fetch failed for {$ym}: " . $e->getMessage());
                continue;
            }
            if (empty($dates)) {
                $this->warn("  [{$ym}] 開催日が取得できませんでした (スキップ)");
                continue;
            }
            foreach ($dates as $d) {
                try {
                    $ids = $scraper->fetchRaceIdsByDate($d);
                } catch (\Throwable $e) {
                    $this->warn("    [{$d}] race_id 取得失敗: " . $e->getMessage());
                    Log::warning("diagnose-dates: race_ids fetch failed for {$d}: " . $e->getMessage());
                    continue;
                }
                foreach ($ids as $rid) {
                    // 同じ race_id が複数日に紐づくことは通常ないが、後勝ち防止で isset ガード
                    if (!isset($officialByRid[$rid])) {
                        $officialByRid[$rid] = $d;
                    }
                }
                $this->line(sprintf('    [%s] race_id %d 件', $d, count($ids)));
            }
        }

        if (empty($officialByRid)) {
            $this->error('netkeiba から公式カレンダー情報を1件も取得できませんでした。中止します。');
            return self::FAILURE;
        }
        $this->info('公式マップ構築: ' . count($officialByRid) . ' race_id');

        // ------------ 突き合わせ ------------
        $mismatches = [];   // ['race' => Race, 'expected' => 'YYYY-MM-DD']
        $missing    = [];   // Race[] : 公式にヒットしなかった (取込ミス or 削除済み)
        $ok         = 0;

        (clone $q)->chunkById(500, function ($rows) use (&$mismatches, &$missing, &$ok, $officialByRid) {
            foreach ($rows as $race) {
                $rid = $race->netkeiba_id;
                $expected = $officialByRid[$rid] ?? null;
                if ($expected === null) {
                    $missing[] = $race;
                    continue;
                }
                $current = optional($race->race_date)->format('Y-m-d');
                if ($current === $expected) {
                    $ok++;
                } else {
                    $mismatches[] = ['race' => $race, 'expected' => $expected, 'current' => $current];
                }
            }
        });

        // ------------ レポート ------------
        $this->newLine();
        $this->info(str_repeat('=', 60));
        $this->info('診断結果');
        $this->info(str_repeat('=', 60));
        $this->line("  一致              : {$ok}");
        $this->line('  不一致            : ' . count($mismatches));
        $this->line('  公式に該当なし    : ' . count($missing) . ' (取込済みだが netkeiba カレンダーに載っていない)');
        $this->newLine();

        if (!empty($mismatches)) {
            $this->warn('--- 不一致リスト ---');
            $show = array_slice($mismatches, 0, 50);
            foreach ($show as $m) {
                $r = $m['race'];
                $this->line(sprintf(
                    '  id=%-6d netkeiba_id=%s  %s   DB:%s  →  公式:%s',
                    $r->id,
                    $r->netkeiba_id,
                    mb_substr($r->name ?? '', 0, 24),
                    $m['current'] ?? '(null)',
                    $m['expected']
                ));
            }
            if (count($mismatches) > 50) {
                $this->line('  …他 ' . (count($mismatches) - 50) . ' 件');
            }
        }

        if (!empty($missing)) {
            $this->newLine();
            $this->warn('--- 公式カレンダーに存在しない race_id (最大20件) ---');
            foreach (array_slice($missing, 0, 20) as $r) {
                $this->line(sprintf(
                    '  id=%-6d netkeiba_id=%s  DB:%s  %s',
                    $r->id,
                    $r->netkeiba_id,
                    optional($r->race_date)->format('Y-m-d') ?? '(null)',
                    mb_substr($r->name ?? '', 0, 24)
                ));
            }
            if (count($missing) > 20) {
                $this->line('  …他 ' . (count($missing) - 20) . ' 件');
            }
            $this->line('  ※ これらは削除された・別カテゴリ・カレンダー未反映などの可能性があるため自動補正の対象にしません。');
        }

        // ------------ --fix ------------
        if (!$doFix) {
            $this->newLine();
            $this->info('--fix 未指定のため更新は行いません。');
            $this->info('補正したい場合: php artisan netkeiba:diagnose-dates --year=' . $year
                . ($month !== null ? " --month={$month}" : '') . ' --fix');
            return self::SUCCESS;
        }

        if (empty($mismatches)) {
            $this->info('不一致なし。補正すべきレコードはありません。');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('--dry-run のため実際には更新しません（上記の不一致リストが更新対象）');
            return self::SUCCESS;
        }

        if (!$this->confirm('上記 ' . count($mismatches) . ' 件の race_date を公式カレンダーの日付に更新します。よろしいですか？', true)) {
            $this->warn('中止しました');
            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($mismatches as $m) {
            $r = $m['race'];
            $r->race_date  = $m['expected'];
            $r->updated_at = now();
            $r->save();
            $updated++;
        }
        $this->info("✓ {$updated} 件の race_date を公式カレンダーの日付に更新しました");

        return self::SUCCESS;
    }
}
