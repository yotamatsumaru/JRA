<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 調教師マスタ
 */
class Trainer extends Model
{
    use HasFactory;

    protected $fillable = [
        'netkeiba_id',
        'name',
        'name_kana',
        'belonging',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(RaceResult::class);
    }
}
