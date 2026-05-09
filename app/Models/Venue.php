<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 競馬場マスタ
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_kana
 * @property string|null $region
 * @property string|null $direction
 * @property int|null $turf_straight
 * @property int|null $dirt_straight
 * @property string|null $characteristics
 */
class Venue extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'name_kana',
        'region',
        'direction',
        'turf_straight',
        'dirt_straight',
        'characteristics',
    ];

    public function races(): HasMany
    {
        return $this->hasMany(Race::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(VenueCourse::class);
    }
}
