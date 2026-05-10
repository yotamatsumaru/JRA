<?php

namespace App\Console\Commands;

use App\Models\Race;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * 既に保存済みの Race の race_date を一括で正しい日付に補正する
 *
 * 用途:
 *   出馬表取込で race_date が誤って入ってしまった既存データを、
 *   呼び出し時に指定した正しい日付で上書きする。
 *
 * 使い方:
 *   # 2026-05-10 に開催されたはずのレースを、その日付で一括補正
 *   php artisan netkeiba:fix-race-dates 2026-05-10
 *
 *   # ある netkeiba_id 範囲だけ対象にする
 *   php artisan netkeiba:fix-race-dates 2026-05-10 --race-id=202605030611
 *
 *   # ある日に取り込まれた誤データだけ対象（updated_at で絞る）
 *   php artisan netkeiba:fix-race-dates 2026-05-10 --imported-on=2026-05-10
 *
 *   # 競馬場を指定（venue_code 2桁）
 *   php artisan netkeiba:fix-race-dates 2026-05-10 --venue=05
 *
 *   # ドライラン（更新せず対象だけ表示）
 *   php artisan netkeiba:fix-race-dates 2026-05-10 --dry-run
 */
class NetkeibaFixRaceDates extends Command
{
    protected $signature = 'netkeiba:fix-race-dates
                            {date : 正しい開催日 (YYYY-MM-DD)。この値で race_date を上書き}
                            {--race-id= : 単一の netkeiba_id (12桁) のみ対象}
                            {--venue= : 競馬場コードで絞込(2桁、複数可カンマ区切り)}
                            {--imported-on= : updated_at がこの日付のレコードのみ対象 (YYYY-MM-DD)}
                            {--only-shutuba : is_shutuba=1 のレコードのみ対象}
                            {--dry-run : 実際には更新せず対象件数を表示}';

    protected $description = '保存済みレースの race_date を指定日で一括補正（出馬表取込で誤った日付が入ってしまった場合の救済）';

    public function handle(): int
    {
        $date = $this->argument('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('date は YYYY-MM-DD 形式で指定してください');
            return self::FAILURE;
        }
        $year = substr($date, 0, 4);

        $query = Race::query();

        // 単一 race_id
        $rid = $this->option('race-id');
        if ($rid !== null && $rid !== '') {
            if (!preg_match('/^\d{12}$/', $rid)) {
                $this->error('--race-id は12桁の数字で指定してください');
                return self::FAILURE;
            }
            $query->where('netkeiba_id', $rid);
        } else {
            // race_id (netkeiba_id) の年部分が補正先の年と一致するもののみ
            $query->where('netkeiba_id', 'like', $year . '%');
        }

        // 競馬場フィルタ
        $venueOpt = $this->option('venue');
        if ($venueOpt !== null && $venueOpt !== '') {
            $venues = array_values(array_filter(array_map(
                fn ($v) => str_pad(trim($v), 2, '0', STR_PAD_LEFT),
                explode(',', $venueOpt)
            )));
            if (!empty($venues)) {
                $query->where(function ($q) use ($venues) {
                    foreach ($venues as $vc) {
                        $q->orWhere('netkeiba_id', 'like', '____' . $vc . '%');
                    }
                });
                $this->info('競馬場フィルタ: ' . implode(',', $venues));
            }
        }

        // imported-on（updated_at）
        $importedOn = $this->option('imported-on');
        if ($importedOn !== null && $importedOn !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $importedOn)) {
                $this->error('--imported-on は YYYY-MM-DD 形式で指定してください');
                return self::FAILURE;
            }
            $query->whereDate('updated_at', $importedOn);
        }

        // 出馬表のみ
        if ((bool) $this->option('only-shutuba')) {
            if (Schema::hasColumn('races', 'is_shutuba')) {
                $query->where('is_shutuba', 1);
            }
        }

        $total = (clone $query)->count();
        $this->info("対象レコード数: {$total}");

        if ($total === 0) {
            $this->warn('対象なし');
            return self::SUCCESS;
        }

        // プレビュー（最大10件）
        $sample = (clone $query)
            ->select(['id', 'netkeiba_id', 'name', 'race_date'])
            ->orderBy('id')
            ->limit(10)
            ->get();
        foreach ($sample as $r) {
            $this->line(sprintf(
                '  id=%-6d netkeiba_id=%s  %s  -> race_date %s ⇒ %s',
                $r->id,
                $r->netkeiba_id,
                $r->name,
                optional($r->race_date)->format('Y-m-d') ?? '(null)',
                $date
            ));
        }
        if ($total > 10) {
            $this->line('  …他 ' . ($total - 10) . ' 件');
        }

        if ((bool) $this->option('dry-run')) {
            $this->warn('--dry-run のため更新しません');
            return self::SUCCESS;
        }

        if (!$this->confirm("これら {$total} 件の race_date を {$date} に更新します。よろしいですか？", true)) {
            $this->warn('中止しました');
            return self::SUCCESS;
        }

        $updated = (clone $query)->update([
            'race_date'  => $date,
            'updated_at' => now(),
        ]);

        $this->info("✓ {$updated} 件の race_date を {$date} に更新しました");
        return self::SUCCESS;
    }
}
