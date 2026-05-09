<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * データ整合性チェック (Phase 5-T)
 *
 *  - 孤立 RaceResult (race_id が races に存在しない / horse_id が horses に存在しない)
 *  - 孤立 RaceMark (race_result_id が race_results に存在しない)
 *  - 孤立 Bet (race_id が races に存在しない)
 *  - 重複 RaceMark (同一 user × race_result に複数行)
 *  - 着順なし (finish_position_int IS NULL) の数 (情報のみ)
 *  - 期限切れ PredictionShare の数 (情報のみ)
 *  - 同一 Race 内の horse_number 重複
 *
 * 使い方:
 *   php artisan jra:check
 *   php artisan jra:check --json     # JSON で結果出力
 *   php artisan jra:check --fix      # 一部の安全な修復 (孤立 race_marks 削除)
 */
class JraCheckCommand extends Command
{
    protected $signature = 'jra:check
                            {--json : JSON 形式で出力}
                            {--fix : 安全に修復可能な不整合を自動修正 (孤立 race_marks の削除など)}';

    protected $description = 'JRA 分析アプリのデータ整合性をチェックする (Phase 5-T)';

    public function handle(): int
    {
        $report = [
            'checked_at' => now()->toDateTimeString(),
            'checks'     => [],
            'fixed'      => [],
            'warnings'   => [],
        ];

        // 各チェックを安全に実行
        $checks = [
            'orphan_race_results_race'    => fn() => $this->orphanRaceResultsByRace(),
            'orphan_race_results_horse'   => fn() => $this->orphanRaceResultsByHorse(),
            'orphan_race_marks'           => fn() => $this->orphanRaceMarks(),
            'orphan_bets'                 => fn() => $this->orphanBets(),
            'duplicate_race_marks'        => fn() => $this->duplicateRaceMarks(),
            'unfinished_results'          => fn() => $this->unfinishedResults(),
            'expired_shares'              => fn() => $this->expiredShares(),
            'duplicate_horse_no_in_race'  => fn() => $this->duplicateHorseNumberInRace(),
        ];

        foreach ($checks as $name => $fn) {
            try {
                $report['checks'][$name] = $fn();
            } catch (\Throwable $e) {
                $report['checks'][$name] = ['error' => $e->getMessage()];
                $report['warnings'][] = "{$name}: {$e->getMessage()}";
            }
        }

        // --fix オプションで安全な修復を実行
        if ($this->option('fix')) {
            $report['fixed'] = $this->applyFixes($report['checks']);
        }

        // 出力
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->renderHumanReport($report);
        return self::SUCCESS;
    }

    /* ===================== チェック実装 ===================== */

    /**
     * 孤立 race_results: race_id に対応する races が存在しない
     */
    protected function orphanRaceResultsByRace(): array
    {
        if (!Schema::hasTable('race_results') || !Schema::hasTable('races')) {
            return ['count' => 0, 'note' => 'table not found'];
        }
        $rows = DB::table('race_results as rr')
            ->leftJoin('races as r', 'r.id', '=', 'rr.race_id')
            ->whereNull('r.id')
            ->select('rr.id', 'rr.race_id')
            ->limit(20)
            ->get();
        $count = DB::table('race_results as rr')
            ->leftJoin('races as r', 'r.id', '=', 'rr.race_id')
            ->whereNull('r.id')
            ->count();
        return ['count' => $count, 'samples' => $rows->take(10)->values()->all()];
    }

    /**
     * 孤立 race_results: horse_id に対応する horses が存在しない
     */
    protected function orphanRaceResultsByHorse(): array
    {
        if (!Schema::hasTable('race_results') || !Schema::hasTable('horses')) {
            return ['count' => 0, 'note' => 'table not found'];
        }
        $count = DB::table('race_results as rr')
            ->leftJoin('horses as h', 'h.id', '=', 'rr.horse_id')
            ->whereNotNull('rr.horse_id')
            ->whereNull('h.id')
            ->count();
        $samples = DB::table('race_results as rr')
            ->leftJoin('horses as h', 'h.id', '=', 'rr.horse_id')
            ->whereNotNull('rr.horse_id')
            ->whereNull('h.id')
            ->select('rr.id', 'rr.race_id', 'rr.horse_id')
            ->limit(10)
            ->get();
        return ['count' => $count, 'samples' => $samples->values()->all()];
    }

    /**
     * 孤立 race_marks: race_result_id が race_results に存在しない
     *  (--fix で削除可能)
     */
    protected function orphanRaceMarks(): array
    {
        if (!Schema::hasTable('race_marks') || !Schema::hasTable('race_results')) {
            return ['count' => 0, 'note' => 'table not found'];
        }
        $ids = DB::table('race_marks as m')
            ->leftJoin('race_results as rr', 'rr.id', '=', 'm.race_result_id')
            ->whereNull('rr.id')
            ->pluck('m.id');
        return [
            'count' => $ids->count(),
            'ids'   => $ids->take(20)->values()->all(),
            'fixable' => true,
        ];
    }

    /**
     * 孤立 bets: race_id が races に存在しない
     */
    protected function orphanBets(): array
    {
        if (!Schema::hasTable('bets')) {
            return ['count' => 0, 'note' => 'table not found'];
        }
        $count = DB::table('bets as b')
            ->leftJoin('races as r', 'r.id', '=', 'b.race_id')
            ->whereNotNull('b.race_id')
            ->whereNull('r.id')
            ->count();
        $samples = DB::table('bets as b')
            ->leftJoin('races as r', 'r.id', '=', 'b.race_id')
            ->whereNotNull('b.race_id')
            ->whereNull('r.id')
            ->select('b.id', 'b.race_id')
            ->limit(10)
            ->get();
        return ['count' => $count, 'samples' => $samples->values()->all()];
    }

    /**
     * 重複 race_marks: 同一 user × race_result に複数行
     */
    protected function duplicateRaceMarks(): array
    {
        if (!Schema::hasTable('race_marks')) {
            return ['count' => 0, 'note' => 'table not found'];
        }
        $rows = DB::table('race_marks')
            ->select('user_id', 'race_result_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id', 'race_result_id')
            ->having('cnt', '>', 1)
            ->orderByDesc('cnt')
            ->limit(20)
            ->get();
        return ['count' => $rows->count(), 'samples' => $rows->values()->all()];
    }

    /**
     * 着順未確定 (情報のみ)
     */
    protected function unfinishedResults(): array
    {
        if (!Schema::hasTable('race_results')) {
            return ['count' => 0, 'note' => 'table not found'];
        }
        $count = DB::table('race_results')->whereNull('finish_position_int')->count();
        $total = DB::table('race_results')->count();
        return [
            'count'  => $count,
            'total'  => $total,
            'ratio'  => $total > 0 ? round($count / $total * 100, 1) : 0,
            'note'   => '結果未取込・未来レースを含む (異常ではありません)',
        ];
    }

    /**
     * 期限切れ PredictionShare (情報のみ)
     */
    protected function expiredShares(): array
    {
        if (!Schema::hasTable('prediction_shares')) {
            return ['count' => 0, 'note' => 'table not found'];
        }
        $count = DB::table('prediction_shares')
            ->where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();
        return [
            'count' => $count,
            'note'  => 'is_active=true なのに expires_at 経過済 (一覧で非閲覧扱いになります)',
        ];
    }

    /**
     * 同一レース内 horse_number 重複
     */
    protected function duplicateHorseNumberInRace(): array
    {
        if (!Schema::hasTable('race_results')) {
            return ['count' => 0, 'note' => 'table not found'];
        }
        $rows = DB::table('race_results')
            ->select('race_id', 'horse_number', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('horse_number')
            ->groupBy('race_id', 'horse_number')
            ->having('cnt', '>', 1)
            ->orderByDesc('cnt')
            ->limit(20)
            ->get();
        return ['count' => $rows->count(), 'samples' => $rows->values()->all()];
    }

    /* ===================== 修復 ===================== */

    /**
     * --fix で安全に修復可能なものだけ自動修正
     */
    protected function applyFixes(array $checks): array
    {
        $fixed = [];

        // 孤立 race_marks → 削除
        $orphanMarks = $checks['orphan_race_marks'] ?? [];
        if (($orphanMarks['count'] ?? 0) > 0 && Schema::hasTable('race_marks')) {
            $deleted = DB::table('race_marks')
                ->whereNotIn('race_result_id', function ($q) {
                    $q->select('id')->from('race_results');
                })->delete();
            $fixed['orphan_race_marks_deleted'] = $deleted;
        }

        return $fixed;
    }

    /* ===================== 出力 ===================== */

    protected function renderHumanReport(array $report): void
    {
        $this->info('=== JRA データ整合性チェック ===');
        $this->line("実行日時: {$report['checked_at']}");
        $this->newLine();

        $rows = [];
        foreach ($report['checks'] as $name => $r) {
            $count = $r['count'] ?? '-';
            $note  = $r['note'] ?? '';
            $status = $this->statusFor($name, (int) ($r['count'] ?? 0));
            $rows[] = [$status, $name, $count, $note];
        }
        $this->table(['Status', 'Check', 'Count', 'Note'], $rows);

        if (!empty($report['warnings'])) {
            $this->newLine();
            $this->warn('警告:');
            foreach ($report['warnings'] as $w) {
                $this->line("  - {$w}");
            }
        }

        if (!empty($report['fixed'])) {
            $this->newLine();
            $this->info('修復済:');
            foreach ($report['fixed'] as $k => $v) {
                $this->line("  - {$k}: {$v}");
            }
        }

        $this->newLine();
        $this->line('詳細を確認: <info>php artisan jra:check --json</info>');
        $this->line('安全な修復: <info>php artisan jra:check --fix</info>');
    }

    /**
     * 各チェックのステータスマッピング
     *  情報系 (unfinished_results / expired_shares) は count > 0 でも OK
     */
    protected function statusFor(string $name, int $count): string
    {
        $informational = ['unfinished_results', 'expired_shares'];
        if ($count === 0) return '<fg=green>OK</>';
        if (in_array($name, $informational, true)) return '<fg=cyan>INFO</>';
        return '<fg=yellow>WARN</>';
    }
}
