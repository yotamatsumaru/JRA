<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * races テーブルに post_time カラム追加 (Phase EV-3)
 *
 * 目的:
 *   これまで race_date (日付型) しか持たなかったため、"発走後 N 分" ガードや
 *   締切時刻の判定が粗く、開催日 0:00 起点の誤判定を起こしていた。
 *   netkeiba の .RaceData01 に含まれる "12:10発走" 文字列を DATETIME で保存し、
 *   精密なオッズ取得ガード / UI 表示に使う。
 *
 * 型:
 *   DATETIME nullable (発走時刻が未取込のレースは null)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('races', function (Blueprint $table) {
            if (!Schema::hasColumn('races', 'post_time')) {
                // race_date の直後に配置 (MariaDB は after 対応)
                $table->dateTime('post_time')->nullable()->after('race_date');
                $table->index('post_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('races', function (Blueprint $table) {
            if (Schema::hasColumn('races', 'post_time')) {
                try { $table->dropIndex(['post_time']); } catch (\Throwable $e) {}
                $table->dropColumn('post_time');
            }
        });
    }
};
