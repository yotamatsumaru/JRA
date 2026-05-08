<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * レース
 *
 * @property int $id
 * @property string|null $netkeiba_id
 * @property int $venue_id
 * @property \Illuminate\Support\Carbon $race_date
 * @property int $race_number
 * @property string $name
 * @property string|null $grade
 * @property string $track_type
 * @property int $distance
 * @property string|null $course_condition
 */
class Race extends Model
{
    use HasFactory;

    protected $fillable = [
        'netkeiba_id',
        'venue_id',
        'race_date',
        'kaisai_kai',
        'kaisai_day',
        'race_number',
        'name',
        'grade',
        'race_class',
        'track_type',
        'distance',
        'direction',
        'course_detail',
        'course_condition',
        'weather',
        'pace',
        'lap_times',
        'first_3f',
        'last_3f',
        'horses_count',
        'first_prize',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'race_date' => 'date',
            'lap_times' => 'array',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(RaceResult::class)->orderBy('finish_position_int');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(RaceNote::class);
    }

    public function winner(): ?RaceResult
    {
        return $this->results()->where('finish_position_int', 1)->first();
    }

    public function getDistanceCategoryAttribute(): string
    {
        return match (true) {
            $this->distance <= 1400 => '短距離',
            $this->distance <= 1800 => 'マイル',
            $this->distance <= 2200 => '中距離',
            $this->distance <= 2600 => '中長距離',
            default => '長距離',
        };
    }

    public function getFullNameAttribute(): string
    {
        $venue = $this->venue?->name ?? '';
        return sprintf(
            '%s %sR %s (%s%dm)',
            $venue,
            $this->race_number,
            $this->name,
            $this->track_type,
            $this->distance
        );
    }
}
