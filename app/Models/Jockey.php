<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * 騎手マスタ
 */
class Jockey extends Model
{
    use HasFactory;

    protected $fillable = [
        'netkeiba_id',
        'name',
        'name_kana',
        'belonging',
        'birthday',
        'debut_date',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'debut_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(RaceResult::class);
    }

    public function favoritedBy(): MorphMany
    {
        return $this->morphMany(Favorite::class, 'favoritable');
    }

    /**
     * 騎手の成績サマリ（期間絞り可）
     */
    public function summary(?string $from = null, ?string $to = null): array
    {
        $query = $this->results()->whereNotNull('finish_position_int');

        if ($from || $to) {
            $query->whereHas('race', function ($q) use ($from, $to) {
                if ($from) {
                    $q->whereDate('race_date', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('race_date', '<=', $to);
                }
            });
        }

        $results = $query->get();
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
