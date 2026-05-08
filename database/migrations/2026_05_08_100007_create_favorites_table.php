<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * お気に入り（馬・騎手）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('favoritable'); // horses, jockeys を対象に
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'favoritable_type', 'favoritable_id'], 'fav_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
