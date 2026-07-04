<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\NetkeibaScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * races.post_time が未設定のレコードに対して netkeiba から発走時刻を穴埋めする
 * (Phase EV-3)
 *
 * netkeiba の出馬表ページ (race.netkeiba.com/race/shutuba.html?race_id=...) の
 * .RaceData01 に含まれる "12:10発走" を抽出。
 *
 * 使い方:
 *   # 未設定のレコードを最大100件穴埋め (デフォルト)
 *   php artisan netkeiba:fill-post-time
 *
 *   # 対象年で絞る
 *   php artisan netkeiba:fill-post-time --year=2026
 *
 *   # 対象月で絞る (race_date で絞込)
 *   php artisan netkeiba:fill-post-time --year=2026 --month=7
 *
 *   # 件数上限
 *   php artisan netkeiba:fill-post-time --limit=50
 *
 *   # 未来レースのみ (取込直後の自動運用向け)
 *   php artisan netkeiba:fill-post-time --only-future
 *
 *   # 既に post_time が入っているレコードも上書きしたい場合
 *   php artisan netkeiba:fill-post-time --overwrite --year=2026 --month=7
 *
 *   # ドライラン (実際には保存しない)
 *   php artisan netkeiba:fill-post-time --dry-run --limit=5
 */
class NetkeibaFillPostTime extends Command
{
    protected $signature = 'netkeiba:fill-post-time
        {--year= : 対象年 (YYYY)}
        {--month= : 対象月 (1-12)。race_date で絞込}
        {--limit=100 : 一度に処理する最大件数 (0=無制限)}
        {--only-future : 今日以降のレースのみ}
        {--overwrite : 既に post_time があるものも再取得して上書き}
        {--sleep=1 : 1件処理ごとのスリープ秒数 (netkeiba 負荷軽減)}
        {--dry-run : 保存せず抽出結果だけ表示}';

    protected $description = 'races.post_time が未設定のレコードを netkeiba の出馬表ページから穴埋め';

    public function handle(NetkeibaScraper $scraper): int
    {
        $year        = $this->option('year');
        $month       = $this->option('month');
        $limit       = (int) $this->option('limit');
        $onlyFuture  = (bool) $this->option('only-future');
        $overwrite   = (bool) $this->option('overwrite');
        $sleep       = max(0, (float) $this->option('sleep'));
        $dryRun      = (bool) $this->option('dry-run');

        $q = Race::query()->whereNotNull('netkeiba_id');

        if (!$overwrite) {
            $q->whereNull('post_time');
        }

        if ($year !== null && $year !== '') {
            if (!preg_match('/^\d{4}$/', (string) $year)) {
                $this->error('--year は YYYY 形式で指定してください');
                return self::FAILURE;
            }
            $q->where('netkeiba_id', 'like', $year . '%');
        }

        if ($month !== null && $month !== '') {
            if (!ctype_digit((string) $month) || (int) $month < 1 || (int) $month > 12) {
                $this->error('--month は 1-12 の整数で指定してください');
                return self::FAILURE;
            }
            $y = $year ?: date('Y');
            $m = (int) $month;
            $q->whereBetween('race_date', [
                sprintf('%04d-%02d-01', (int) $y, $m),
                date('Y-m-t', mktime(0, 0, 0, $m, 1, (int) $y)),
            ]);
        }

        if ($onlyFuture) {
            $q->whereDate('race_date', '>=', now()->toDateString());
        }

        $q->orderBy('race_date')->orderBy('race_number');
        if ($limit > 0) {
            $q->limit($limit);
        }

        $total = (clone $q)->count();
        $this->info("対象件数: {$total}");
        if ($total === 0) {
            $this->warn('対象なし');
            return self::SUCCESS;
        }

        $filled = 0;
        $skipped = 0;
        $errors = 0;

        $q->chunkById(50, function ($races) use (&$filled, &$skipped, &$errors, $scraper, $dryRun, $sleep) {
            foreach ($races as $race) {
                try {
                    // fetchShutuba() を叩いて .RaceData01 経由で post_time を得る
                    $shutuba = $scraper->fetchShutuba($race->netkeiba_id);
                    $pt = $shutuba['post_time'] ?? null;

                    if (!$pt) {
                        $skipped++;
                        $this->line(sprintf(
                            '  ⚠ id=%-6d %s: 発走時刻を抽出できませんでした',
                            $race->id, $race->netkeiba_id
                        ));
                        if ($sleep > 0) usleep((int) ($sleep * 1_000_000));
                        continue;
                    }

                    if ($dryRun) {
                        $this->line(sprintf(
                            '  [dry-run] id=%-6d %s: post_time <= %s',
                            $race->id, $race->netkeiba_id, $pt
                        ));
                    } else {
                        $race->post_time = $pt;
                        $race->save();
                        $this->line(sprintf(
                            '  ✓ id=%-6d %s: post_time <= %s',
                            $race->id, $race->netkeiba_id, $pt
                        ));
                    }
                    $filled++;
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning("fill-post-time failed race#{$race->id}: " . $e->getMessage());
                    $this->line(sprintf(
                        '  ✗ id=%-6d %s: エラー: %s',
                        $race->id, $race->netkeiba_id, $e->getMessage()
                    ));
                }

                if ($sleep > 0) usleep((int) ($sleep * 1_000_000));
            }
        });

        $this->newLine();
        $this->info("完了: 更新 {$filled} 件 / スキップ {$skipped} 件 / エラー {$errors} 件"
            . ($dryRun ? '  (dry-run のため実際には保存していません)' : ''));

        return self::SUCCESS;
    }
}
