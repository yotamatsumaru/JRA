<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Models\RaceResult;
use App\Services\NetkeibaScraper;
use App\Services\RaceImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 過去レースで単勝オッズ (race_results.win_odds) や人気 (race_results.popularity)
 * が未取込のものを netkeiba から穴埋めするコマンド (Phase EV-3)
 *
 * 対象:
 *   - race_date < 今日 (過去のレース)
 *   - race_results.win_odds が NULL の行が 1 件でもあるレース
 *   - --overwrite 指定時は win_odds の有無に関わらず全過去レースが対象
 *
 * 処理:
 *   - NetkeibaScraper::fetchRace() でレース結果ページを取得
 *   - RaceImportService::importFromNetkeiba() で race_results を UPSERT (delete → re-insert)
 *     → win_odds / popularity のみでなく、レース結果全体が最新値で同期される
 *   - 1 レース処理ごとに --sleep 秒待機して netkeiba への負荷を抑える
 *
 * 使い方:
 *   # デフォルト: win_odds が未取込の過去レース最大100件を更新
 *   php artisan netkeiba:fill-past-odds
 *
 *   # 期間指定
 *   php artisan netkeiba:fill-past-odds --from=2025-01-01 --to=2026-07-04
 *
 *   # 件数上限を変更
 *   php artisan netkeiba:fill-past-odds --limit=500
 *
 *   # スリープ秒数を変更 (netkeiba 負荷調整)
 *   php artisan netkeiba:fill-past-odds --sleep=5
 *
 *   # 既に win_odds が入っているレースも再取得
 *   php artisan netkeiba:fill-past-odds --overwrite
 *
 *   # ドライラン (対象一覧だけ表示・保存しない)
 *   php artisan netkeiba:fill-past-odds --dry-run --limit=20
 *
 *   # 特定年だけを一気に穴埋め
 *   php artisan netkeiba:fill-past-odds --year=2025 --sleep=3
 */
class NetkeibaFillPastOdds extends Command
{
    protected $signature = 'netkeiba:fill-past-odds
        {--from= : 対象期間の開始日 (YYYY-MM-DD)}
        {--to= : 対象期間の終了日 (YYYY-MM-DD)}
        {--year= : 対象年 (YYYY)。--from/--to より優先度は低い}
        {--limit=100 : 一度に処理する最大レース数 (0=無制限)}
        {--sleep=3 : 1レース処理ごとのスリープ秒数 (netkeiba 負荷軽減)}
        {--overwrite : win_odds が入っているレースも強制的に再取得}
        {--dry-run : 実際には保存せず対象リストだけ表示}';

    protected $description = 'race_results.win_odds が未取込の過去レースを netkeiba から穴埋め';

    public function handle(NetkeibaScraper $scraper, RaceImportService $importer): int
    {
        $from      = $this->option('from');
        $to        = $this->option('to');
        $year      = $this->option('year');
        $limit     = (int) $this->option('limit');
        $sleep     = max(0.0, (float) $this->option('sleep'));
        $overwrite = (bool) $this->option('overwrite');
        $dryRun    = (bool) $this->option('dry-run');

        // ============ バリデーション ============
        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            if ($v !== null && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $this->error("--{$k} は YYYY-MM-DD 形式で指定してください");
                return self::FAILURE;
            }
        }
        if ($year !== null && $year !== '' && !preg_match('/^\d{4}$/', (string) $year)) {
            $this->error('--year は YYYY 形式で指定してください');
            return self::FAILURE;
        }

        // ============ 対象レース抽出 ============
        // 過去 (race_date < 今日) & netkeiba_id がある レースが基底集合。
        // 既に win_odds が入っているレースは通常スキップし、--overwrite 指定時のみ含める。
        $today = now()->toDateString();
        $q = Race::query()
            ->whereNotNull('netkeiba_id')
            ->whereDate('race_date', '<', $today);

        if ($from) {
            $q->whereDate('race_date', '>=', $from);
        }
        if ($to) {
            $q->whereDate('race_date', '<=', $to);
        }
        if (!$from && !$to && $year) {
            $q->whereBetween('race_date', ["{$year}-01-01", "{$year}-12-31"]);
        }

        if (!$overwrite) {
            // race_results.win_odds が NULL の行を 1 件でも含むレースだけを対象に
            //   ─ NOT EXISTS ではなく EXISTS (win_odds NULL) を条件にする
            //   ─ 全馬 win_odds が入っているレースは "取込済み" 扱いでスキップ
            $q->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('race_results as rr_null')
                    ->whereColumn('rr_null.race_id', 'races.id')
                    ->whereNull('rr_null.win_odds');
            });

            // かつ、そもそも race_results が 1 件も無いレース (=出馬表すら未取込)
            // も除外する。fetchRace() は結果ページを見に行くので、
            // 出馬表インポート直後の未来レースを誤って含めないため。
            $q->whereHas('results');
        }

        $q->orderBy('race_date')->orderBy('race_number');

        $total = (clone $q)->count();
        $this->info("対象レース: {$total} 件"
            . ($overwrite ? '  (--overwrite: 既取込含む)' : '  (win_odds 未取込のみ)')
            . ($dryRun ? '  [dry-run]' : ''));

        if ($limit > 0) {
            $q->limit($limit);
            $this->line("  今回処理: 最大 {$limit} 件");
        }

        if ($total === 0) {
            $this->warn('対象なし。すべて取込済みです。');
            return self::SUCCESS;
        }

        // dry-run の場合は結果だけ出して終了
        if ($dryRun) {
            $this->newLine();
            $this->info('=== 対象レース一覧 (dry-run) ===');
            $q->get(['id', 'netkeiba_id', 'race_date', 'race_number', 'name'])
                ->each(function ($r) {
                    $this->line(sprintf(
                        '  id=%-6d %s  %s R%02d  %s',
                        $r->id,
                        $r->netkeiba_id,
                        $r->race_date->format('Y-m-d'),
                        $r->race_number,
                        $r->name
                    ));
                });
            $this->newLine();
            $this->info('dry-run のため実際には取得・保存していません。');
            return self::SUCCESS;
        }

        // ============ 実行 ============
        $filled  = 0;
        $skipped = 0;
        $errors  = 0;
        $processed = 0;

        $q->chunkById(50, function ($races) use (
            &$filled, &$skipped, &$errors, &$processed,
            $scraper, $importer, $sleep, $total
        ) {
            foreach ($races as $race) {
                $processed++;
                $prefix = sprintf('  [%d/%d] id=%-6d %s %s R%02d',
                    $processed, $total, $race->id, $race->netkeiba_id,
                    $race->race_date->format('Y-m-d'), $race->race_number
                );

                try {
                    // netkeiba のレース結果ページを取得
                    $data = $scraper->fetchRace(
                        $race->netkeiba_id,
                        $race->race_date->format('Y-m-d')
                    );

                    // 対象レースの win_odds を含む行数を計測 (取込効果の可視化)
                    $withOdds = 0;
                    foreach (($data['results'] ?? []) as $row) {
                        if (isset($row['win_odds']) && $row['win_odds'] !== null && $row['win_odds'] !== '') {
                            $withOdds++;
                        }
                    }

                    if ($withOdds === 0) {
                        // netkeiba 側にオッズがない (レース中止・非公開など)
                        $skipped++;
                        $this->line("$prefix  ⚠ netkeiba 側にオッズ無し (スキップ)");
                        if ($sleep > 0) usleep((int) ($sleep * 1_000_000));
                        continue;
                    }

                    // UPSERT (RaceImportService::importFromNetkeiba は delete → re-insert)
                    $importer->importFromNetkeiba($data);

                    // 更新後の実際の win_odds 入り件数
                    $after = RaceResult::where('race_id', $race->id)
                        ->whereNotNull('win_odds')
                        ->count();

                    $filled++;
                    $this->line("$prefix  ✓ win_odds 入 {$after} 頭 / netkeiba 上 {$withOdds} 頭");
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning("fill-past-odds failed race#{$race->id}: " . $e->getMessage());
                    $this->line("$prefix  ✗ エラー: " . $e->getMessage());
                }

                if ($sleep > 0) usleep((int) ($sleep * 1_000_000));
            }
        });

        $this->newLine();
        $this->info("完了: 更新 {$filled} 件 / スキップ {$skipped} 件 / エラー {$errors} 件");

        return self::SUCCESS;
    }
}
