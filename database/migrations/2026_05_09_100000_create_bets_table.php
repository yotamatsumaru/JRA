<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 馬券購入記録（ヘッダ）
 *
 *  - 1レース × 1券種 × 1買い方 = 1レコード
 *  - 個別の買い目組合せは bet_legs に展開保存
 *  - 的中判定・払戻金額はレース結果と突合して自動算出（手動上書きも可）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();

            // 券種: tan / fuku / waku-ren / uma-ren / uma-tan / wide / san-fuku / san-tan / win5
            $table->string('kind', 16)->index();

            // 買い方: single / box / formation
            $table->string('method', 16)->default('single');

            // 1点あたりの金額（円） / 点数 / 合計投資額
            $table->unsignedInteger('unit_stake')->default(100)->comment('1点あたり単価(円)');
            $table->unsignedSmallInteger('points')->default(1)->comment('点数');
            $table->unsignedInteger('total_stake')->default(0)->comment('合計投資額(円) = unit_stake × points');

            // 結果サマリ（bet_legs から集計してキャッシュ）
            $table->unsignedSmallInteger('hit_count')->default(0)->comment('的中点数');
            $table->unsignedInteger('total_return')->default(0)->comment('払戻総額(円)');
            $table->boolean('is_settled')->default(false)->comment('精算済（レース確定後にtrue）');

            // フォーメーション情報の生表現（参考保存用 / 再編集UI用）
            // 例: { "axis":[3], "second":[1,5,7], "third":[1,5,7,9] }
            $table->json('selection')->nullable()->comment('選択内容（軸・相手など）');

            // 購入日時・メモ
            $table->dateTime('purchased_at')->nullable();
            $table->text('memo')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'race_id']);
            $table->index(['user_id', 'is_settled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bets');
    }
};
