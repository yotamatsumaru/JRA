<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * race_results にダッシュボード/詳細ページ高速化用のインデックスを追加。
 *
 * 主な対象クエリ:
 *   - WHERE finish_position_int IS NOT NULL ... GROUP BY ...
 *     (ダッシュボードの venueTrackWinRate / frameWinRates / venueStyleStats)
 *   - WHERE jockey_id = ? AND finish_position_int = 1   (騎手詳細)
 *   - WHERE trainer_id = ? AND finish_position_int <= 3 (調教師詳細)
 *   - WHERE horse_id = ? AND finish_position_int IS NOT NULL (馬詳細)
 *   - GROUP BY race_id, frame_number  (frameWinRates)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 既存インデックス名を取得（同名の重複作成を避ける）
        $existing = $this->existingIndexNames('race_results');

        Schema::table('race_results', function (Blueprint $table) use ($existing) {
            // 1) finish_position_int 単独。 IS NOT NULL のフィルタで使う
            if (!$this->indexCovers($existing, ['finish_position_int'])) {
                $table->index('finish_position_int', 'race_results_fpos_idx');
            }

            // 2) trainer_id 単独 (現状は jockey_id しか単独索引が無い)
            if (!$this->indexCovers($existing, ['trainer_id'])) {
                $table->index('trainer_id', 'race_results_trainer_idx');
            }

            // 3) (horse_id, finish_position_int) — 馬詳細の集計
            if (!$this->indexCovers($existing, ['horse_id', 'finish_position_int'])) {
                $table->index(['horse_id', 'finish_position_int'], 'race_results_horse_fpos_idx');
            }

            // 4) (jockey_id, finish_position_int) — 騎手詳細の集計
            if (!$this->indexCovers($existing, ['jockey_id', 'finish_position_int'])) {
                $table->index(['jockey_id', 'finish_position_int'], 'race_results_jockey_fpos_idx');
            }

            // 5) (trainer_id, finish_position_int) — 調教師詳細の集計
            if (!$this->indexCovers($existing, ['trainer_id', 'finish_position_int'])) {
                $table->index(['trainer_id', 'finish_position_int'], 'race_results_trainer_fpos_idx');
            }

            // 6) (race_id, frame_number) — frameWinRates GROUP BY 用
            //    既存の unique(race_id, horse_number) は frame_number に効かない
            if (!$this->indexCovers($existing, ['race_id', 'frame_number'])) {
                $table->index(['race_id', 'frame_number'], 'race_results_race_frame_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('race_results', function (Blueprint $table) {
            foreach ([
                'race_results_fpos_idx',
                'race_results_trainer_idx',
                'race_results_horse_fpos_idx',
                'race_results_jockey_fpos_idx',
                'race_results_trainer_fpos_idx',
                'race_results_race_frame_idx',
            ] as $name) {
                try {
                    $table->dropIndex($name);
                } catch (\Throwable $e) {
                    // ignore — already dropped or never created
                }
            }
        });
    }

    /**
     * テーブルの既存インデックス情報をカラム配列の連想配列で返す。
     * 例: ['idx_name' => ['horse_id','finish_position_int'], ...]
     */
    protected function existingIndexNames(string $table): array
    {
        try {
            $rows = DB::select("SHOW INDEX FROM `{$table}`");
        } catch (\Throwable $e) {
            return [];
        }
        $byName = [];
        foreach ($rows as $r) {
            $name = $r->Key_name ?? null;
            $col  = $r->Column_name ?? null;
            $seq  = (int) ($r->Seq_in_index ?? 0);
            if (!$name || !$col) continue;
            $byName[$name][$seq] = $col;
        }
        // sort by seq and reduce to columns array
        foreach ($byName as $n => $cols) {
            ksort($cols);
            $byName[$n] = array_values($cols);
        }
        return $byName;
    }

    /**
     * 既存インデックスのいずれかが、欲しいカラム並びを「先頭から完全に」カバーしているか
     * (= 同等以上のインデックスが既に存在するか) を判定。
     */
    protected function indexCovers(array $existing, array $wanted): bool
    {
        foreach ($existing as $cols) {
            if (count($cols) < count($wanted)) continue;
            $prefix = array_slice($cols, 0, count($wanted));
            if ($prefix === $wanted) return true;
        }
        return false;
    }
};
