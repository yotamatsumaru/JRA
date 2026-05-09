<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * スケジューラ実行ログ (Phase 3-J)
     *  - 定期実行された artisan コマンドの結果を記録
     *  - 開始/終了時刻・出力・成否
     */
    public function up(): void
    {
        Schema::create('scheduler_logs', function (Blueprint $table) {
            $table->id();
            $table->string('job', 100)->index();      // 'netkeiba:date', 'bets:resettle', 'app:backup' など
            $table->string('status', 16)->default('running');   // running / success / failed
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->integer('exit_code')->nullable();
            $table->mediumText('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['job', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_logs');
    }
};
