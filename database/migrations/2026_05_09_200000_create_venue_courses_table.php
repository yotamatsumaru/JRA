<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 競馬場 × トラック種別 × 距離 のコース情報マスタ
 *
 * Venue (各場) との関係: 1場が複数距離コースを持つ (1:N)
 * 1コース = (venue_id, track_type, distance) の3項組で一意
 *
 * Venue 自身は「場全体の直線長・特徴」を持つが、
 * このテーブルは「各距離の有利脚質・有利枠・スタート位置・コーナー数等」の詳細を持つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('track_type', 10)->comment('芝 / ダート / 障害');
            $table->integer('distance')->comment('距離(m)');
            $table->string('course_variation', 10)->nullable()
                ->comment('コース区分 A/B/C/D 等 (内回り/外回り含む)');

            // 形状情報
            $table->integer('straight_length')->nullable()->comment('最後の直線長(m)');
            $table->decimal('elevation_diff', 4, 1)->nullable()->comment('高低差(m)');
            $table->tinyInteger('corner_count')->nullable()->comment('コーナー数');
            $table->string('start_position', 30)->nullable()->comment('スタート位置 例:向正面・2コーナー奥');

            // 傾向情報
            $table->string('favored_style', 20)->nullable()
                ->comment('有利脚質 例: 先行,差し');
            $table->string('favored_frame', 20)->nullable()
                ->comment('有利枠 例: 内,中');
            $table->string('pace_tendency', 10)->nullable()
                ->comment('ペース傾向 ハイ/ミドル/スロー');

            // フリーテキスト
            $table->text('notes')->nullable()->comment('コース特徴(中粒度コメント)');

            $table->timestamps();

            $table->unique(['venue_id', 'track_type', 'distance', 'course_variation'], 'venue_courses_unique');
            $table->index(['track_type', 'distance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_courses');
    }
};
