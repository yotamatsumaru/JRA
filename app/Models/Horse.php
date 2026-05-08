<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * 馬マスタ
 *
 * @property int $id
 * @property string|null $netkeiba_id
 * @property string $name
 * @property string|null $sex
 * @property \Illuminate\Support\Carbon|null $birthday
 * @property string|null $father
 * @property string|null $mother
 * @property string|null $mother_father
 */
class Horse extends Model
{
    use HasFactory;

    protected $fillable = [
        'netkeiba_id',
        'name',
        'name_kana',
        'name_en',
        'sex',
        'birthday',
        'color',
        'father',
        'mother',
        'mother_father',
        'owner',
        'breeder',
        'birth_place',
        'total_prize',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'total_prize' => 'integer',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(RaceResult::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(RaceNote::class);
    }

    public function favoritedBy(): MorphMany
    {
        return $this->morphMany(Favorite::class, 'favoritable');
    }

    /**
     * 通算成績サマリ
     */
    public function summary(): array
    {
        $results = $this->results()->whereNotNull('finish_position_int')->get();
        $total = $results->count();
        $wins = $results->where('finish_position_int', 1)->count();
        $places = $results->whereIn('finish_position_int', [1, 2])->count();
        $shows = $results->whereIn('finish_position_int', [1, 2, 3])->count();

        return [
            'total' => $total,
            'wins' => $wins,
            'places' => $places,
            'shows' => $shows,
            'win_rate' => $total > 0 ? round($wins / $total * 100, 1) : 0,
            'place_rate' => $total > 0 ? round($places / $total * 100, 1) : 0,
            'show_rate' => $total > 0 ? round($shows / $total * 100, 1) : 0,
        ];
    }
}
