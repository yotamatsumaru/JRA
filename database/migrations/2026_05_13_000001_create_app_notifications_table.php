<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 通知 (Phase 6-A)
     *  - ウォッチリスト対象の出走予定や、共有予想の閲覧などをユーザーに通知
     *  - type 例: 'watchlist_entry' / 'share_expiring' / 'system'
     *  - read_at がセットされていれば既読
     */
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->json('payload')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
