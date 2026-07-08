<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * races テーブルに course_condition_checked_at カラム追加 (Phase EV-5)
 *
 * 目的:
 *   出馬表の「最新オッズ取得」実行時に netkeiba .RaceData01 から
 *   天候(weather) / 馬場状態(course_condition) を同時取得し races テーブルへ
 *   反映するようにした (リアルタイム馬場状況機能)。
 *
 *   このとき「いつ時点で確認された値か」を UI に表示するため、
 *   live_odds_at と同様の "確認時刻" カラムを追加する。
 *   値そのものが変化していなくても、確認できた時点で更新する
 *   (= 「◯時◯分現在、変わらず良馬場」という情報にも意味があるため)。
 *
 * 型:
 *   DATETIME nullable (まだ一度もライブ確認されていないレースは null。
 *   その場合は出馬表インポート時点の静的な値のみが表示される)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('races', function (Blueprint $table) {
            if (!Schema::hasColumn('races', 'course_condition_checked_at')) {
                $table->dateTime('course_condition_checked_at')->nullable()->after('course_condition');
            }
        });
    }

    public function down(): void
    {
        Schema::table('races', function (Blueprint $table) {
            if (Schema::hasColumn('races', 'course_condition_checked_at')) {
                $table->dropColumn('course_condition_checked_at');
            }
        });
    }
};
