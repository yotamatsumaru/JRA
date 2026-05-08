<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * レーステーブル
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('races', function (Blueprint $table) {
            $table->id();
            $table->string('netkeiba_id', 20)->nullable()->unique()->comment('netkeibaのrace_id');
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->date('race_date')->comment('開催日');
            $table->unsignedTinyInteger('kaisai_kai')->nullable()->comment('開催回 (例:第3回)');
            $table->unsignedTinyInteger('kaisai_day')->nullable()->comment('開催日次 (例:8日目)');
            $table->unsignedTinyInteger('race_number')->comment('R (1-12)');
            $table->string('name', 100)->comment('レース名');
            $table->string('grade', 20)->nullable()->comment('G1/G2/G3/L/OP/3勝/2勝/1勝/未勝利/新馬');
            $table->string('race_class', 30)->nullable()->comment('クラス詳細');
            $table->enum('track_type', ['芝', 'ダート', '障害'])->comment('トラック種別');
            $table->unsignedSmallInteger('distance')->comment('距離(m)');
            $table->enum('direction', ['右', '左', '直線'])->nullable()->comment('回り');
            $table->string('course_detail', 30)->nullable()->comment('内/外/A/B/C等');
            $table->enum('course_condition', ['良', '稍重', '重', '不良'])->nullable()->comment('馬場状態');
            $table->string('weather', 10)->nullable()->comment('天候');
            $table->enum('pace', ['H', 'M', 'S'])->nullable()->comment('ペース H=ハイ M=ミドル S=スロー');
            $table->json('lap_times')->nullable()->comment('ラップタイム配列');
            $table->string('first_3f', 10)->nullable()->comment('前半3F');
            $table->string('last_3f', 10)->nullable()->comment('後半3F');
            $table->unsignedTinyInteger('horses_count')->nullable()->comment('出走頭数');
            $table->bigInteger('first_prize')->nullable()->comment('1着賞金(万円)');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['race_date', 'venue_id']);
            $table->index('grade');
            $table->index(['track_type', 'distance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('races');
    }
};
