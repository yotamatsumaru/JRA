<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * インポート履歴（CSV / netkeiba / 画像）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['manual', 'csv', 'netkeiba', 'image'])->comment('インポート元');
            $table->string('reference', 200)->nullable()->comment('URL/ファイル名等');
            $table->enum('status', ['pending', 'processing', 'success', 'partial', 'failed'])->default('pending');
            $table->unsignedInteger('records_total')->default(0);
            $table->unsignedInteger('records_imported')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->json('payload')->nullable()->comment('追加情報・パラメータ');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'source']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
