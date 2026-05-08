<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 調教師マスタ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('netkeiba_id', 20)->nullable()->unique();
            $table->string('name', 50);
            $table->string('name_kana', 100)->nullable();
            $table->string('belonging', 30)->nullable()->comment('美浦/栗東');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainers');
    }
};
