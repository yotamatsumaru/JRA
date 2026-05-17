<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * race_marks に枠/コース/脚質スコアの3カラムを追加。
 *
 * - score_frame  : 枠順スコア(同枠×同コースの過去複勝率)
 * - score_course : コーススコア(同馬×同方向の過去複勝率)
 * - score_style  : 脚質スコア(馬の脚質 × 想定ペース)
 *
 * いずれも 0-100 の DECIMAL(5,2)。NULL 可。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_marks', function (Blueprint $table) {
            $table->decimal('score_frame',  5, 2)->nullable()->after('score_roi');
            $table->decimal('score_course', 5, 2)->nullable()->after('score_frame');
            $table->decimal('score_style',  5, 2)->nullable()->after('score_course');
        });
    }

    public function down(): void
    {
        Schema::table('race_marks', function (Blueprint $table) {
            $table->dropColumn(['score_frame', 'score_course', 'score_style']);
        });
    }
};
