<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * レース結果テーブル（出走馬1頭につき1レコード）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained('races')->cascadeOnDelete();
            $table->foreignId('horse_id')->constrained('horses')->cascadeOnDelete();
            $table->foreignId('jockey_id')->nullable()->constrained('jockeys')->nullOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('trainers')->nullOnDelete();

            // 着順関連
            $table->string('finish_position', 5)->nullable()->comment('着順 1-18 / 取消(中止)/除外/失格');
            $table->unsignedTinyInteger('finish_position_int')->nullable()->comment('着順数値（中止等はnull）');
            $table->unsignedTinyInteger('frame_number')->nullable()->comment('枠番 1-8');
            $table->unsignedTinyInteger('horse_number')->comment('馬番 1-18');

            // 馬の状態
            $table->enum('sex', ['牡', '牝', 'セ'])->nullable();
            $table->unsignedTinyInteger('age')->nullable()->comment('年齢');
            $table->decimal('weight_carried', 4, 1)->nullable()->comment('斤量(kg)');
            $table->unsignedSmallInteger('horse_weight')->nullable()->comment('馬体重(kg)');
            $table->smallInteger('horse_weight_diff')->nullable()->comment('馬体重増減');

            // タイム関連
            $table->string('time', 10)->nullable()->comment('タイム 例:1:23.4');
            $table->decimal('time_seconds', 6, 2)->nullable()->comment('タイム秒換算');
            $table->string('margin', 10)->nullable()->comment('着差');
            $table->string('last_3f', 6)->nullable()->comment('上がり3F');
            $table->decimal('last_3f_seconds', 4, 1)->nullable();

            // 通過順位
            $table->string('corner_positions', 30)->nullable()->comment('通過順 例:3-3-2-1');
            $table->string('running_style', 10)->nullable()->comment('脚質 逃/先/差/追/マ');

            // オッズ・人気
            $table->unsignedTinyInteger('popularity')->nullable()->comment('単勝人気');
            $table->decimal('win_odds', 7, 1)->nullable()->comment('単勝オッズ');
            $table->decimal('place_odds_min', 6, 1)->nullable()->comment('複勝オッズ最低');
            $table->decimal('place_odds_max', 6, 1)->nullable()->comment('複勝オッズ最高');

            // 賞金
            $table->bigInteger('prize_money')->nullable()->comment('獲得賞金(万円)');

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['race_id', 'horse_number']);
            $table->index(['race_id', 'finish_position_int']);
            $table->index('horse_id');
            $table->index('jockey_id');
            $table->index('running_style');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_results');
    }
};
