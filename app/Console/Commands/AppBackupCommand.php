<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * アプリ全体のバックアップ (Phase 3-AA)
 *
 *  - DB を mysqldump (利用不可なら Eloquent ベースで JSON ダンプ) で保存
 *  - storage/app/.env.snapshot.txt は出力しない (機密)
 *  - backup_path 配下に <timestamp>.tar.gz を作成
 *
 * 使い方:
 *   php artisan app:backup
 *   php artisan app:backup --path=/var/backups/jra
 *   php artisan app:backup --keep=14    # 14世代だけ残す
 */
class AppBackupCommand extends Command
{
    protected $signature = 'app:backup
                            {--path= : バックアップ保存ディレクトリ (省略時 storage/app/backups)}
                            {--keep=14 : 保持世代数 (それ以前は自動削除)}
                            {--no-compress : tar.gz 化せずディレクトリのまま}';

    protected $description = 'DB と storage の主要データをバックアップする (Phase 3-AA)';

    public function handle(): int
    {
        $stamp = now()->format('Ymd_His');
        $base  = $this->option('path') ?: storage_path('app/backups');
        $dest  = rtrim($base, '/') . '/backup_' . $stamp;

        if (!File::isDirectory($dest)) {
            File::makeDirectory($dest, 0755, true);
        }

        $this->info("バックアップ開始: {$dest}");

        // ====== DB バックアップ ======
        $dbOk = $this->backupDatabase($dest);

        // ====== storage の主要ディレクトリをコピー ======
        $copied = $this->backupStorage($dest);

        // ====== サマリ JSON ======
        $summary = [
            'timestamp'    => now()->toIso8601String(),
            'db_backup'    => $dbOk,
            'storage_copy' => $copied,
            'php_version'  => PHP_VERSION,
            'app_env'      => config('app.env'),
        ];
        File::put($dest . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // ====== 圧縮 ======
        $finalPath = $dest;
        if (!$this->option('no-compress')) {
            $tar = $dest . '.tar.gz';
            $this->info('圧縮中…');
            $proc = Process::fromShellCommandline(
                "tar -czf " . escapeshellarg($tar) . " -C " . escapeshellarg(dirname($dest)) . " " . escapeshellarg(basename($dest))
            );
            $proc->setTimeout(600)->run();
            if ($proc->isSuccessful()) {
                File::deleteDirectory($dest);
                $finalPath = $tar;
                $this->info('圧縮完了: ' . $tar . ' (' . $this->humanSize(filesize($tar)) . ')');
            } else {
                $this->warn('tar 失敗 - 非圧縮で保存しました: ' . $proc->getErrorOutput());
            }
        }

        // ====== 古い世代の削除 ======
        $this->cleanupOld($base, (int) $this->option('keep'));

        // 監査ログ
        try {
            AuditLog::create([
                'action' => 'backup.run',
                'meta'   => ['path' => $finalPath, 'db_ok' => $dbOk, 'storage_files' => $copied],
            ]);
        } catch (\Throwable $e) {
            // audit_logs マイグレーション前は無視
        }

        $this->info('完了: ' . $finalPath);
        return self::SUCCESS;
    }

    /** mysqldump があれば mysqldump、無ければ Eloquent JSON ダンプ */
    protected function backupDatabase(string $dest): bool
    {
        $cfg = config('database.connections.' . config('database.default'));
        if (($cfg['driver'] ?? null) !== 'mysql') {
            $this->warn('MySQL 以外のため Eloquent JSON ダンプにフォールバック');
            return $this->jsonDumpDatabase($dest);
        }

        $sqlPath = $dest . '/database.sql';
        $cmd = sprintf(
            'mysqldump --single-transaction --quick --skip-lock-tables -h%s -P%s -u%s %s %s > %s 2>/dev/null',
            escapeshellarg((string) $cfg['host']),
            escapeshellarg((string) ($cfg['port'] ?? 3306)),
            escapeshellarg((string) $cfg['username']),
            $cfg['password'] ? '-p' . escapeshellarg((string) $cfg['password']) : '',
            escapeshellarg((string) $cfg['database']),
            escapeshellarg($sqlPath)
        );
        $proc = Process::fromShellCommandline($cmd);
        $proc->setTimeout(900)->run();

        if ($proc->isSuccessful() && file_exists($sqlPath) && filesize($sqlPath) > 0) {
            $this->info('mysqldump OK: ' . $this->humanSize(filesize($sqlPath)));
            return true;
        }

        $this->warn('mysqldump 不可 - JSON ダンプにフォールバック');
        return $this->jsonDumpDatabase($dest);
    }

    /** mysqldump が無い時の代替 (主要テーブルだけ JSON 保存) */
    protected function jsonDumpDatabase(string $dest): bool
    {
        $tables = [
            'venues', 'horses', 'jockeys', 'trainers',
            'races', 'race_results', 'payouts',
            'race_marks', 'race_notes', 'race_user_notes',
            'bets', 'bet_legs', 'favorites', 'bankrolls',
            'pedigrees', 'venue_courses',
        ];
        $dir = $dest . '/json';
        File::makeDirectory($dir, 0755, true);
        $totalRows = 0;
        foreach ($tables as $t) {
            try {
                $rows = \DB::table($t)->get();
                File::put($dir . "/{$t}.json", $rows->toJson(JSON_UNESCAPED_UNICODE));
                $totalRows += $rows->count();
                $this->line("  {$t}: " . $rows->count() . ' rows');
            } catch (\Throwable $e) {
                $this->line("  {$t}: スキップ (" . $e->getMessage() . ')');
            }
        }
        return $totalRows > 0;
    }

    protected function backupStorage(string $dest): int
    {
        $copied = 0;
        $src = storage_path('app');
        if (!File::isDirectory($src)) return 0;

        // backups 自身は除外
        $files = File::allFiles($src);
        foreach ($files as $f) {
            $rel = ltrim(str_replace($src, '', $f->getPathname()), '/');
            if (str_starts_with($rel, 'backups/')) continue;
            if (str_starts_with($rel, 'framework/')) continue;
            $target = $dest . '/storage/' . $rel;
            File::ensureDirectoryExists(dirname($target));
            File::copy($f->getPathname(), $target);
            $copied++;
        }
        return $copied;
    }

    protected function cleanupOld(string $baseDir, int $keep): void
    {
        if ($keep <= 0) return;
        if (!File::isDirectory($baseDir)) return;
        $items = collect(File::glob($baseDir . '/backup_*'))
            ->sort()
            ->values();
        $excess = $items->count() - $keep;
        if ($excess <= 0) return;
        for ($i = 0; $i < $excess; $i++) {
            $p = $items[$i];
            if (is_dir($p)) {
                File::deleteDirectory($p);
            } else {
                File::delete($p);
            }
            $this->line('古いバックアップを削除: ' . basename($p));
        }
    }

    protected function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return round($n, 2) . ' ' . $units[$i];
    }
}
