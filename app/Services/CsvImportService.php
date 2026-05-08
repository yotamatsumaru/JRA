<?php

namespace App\Services;

use App\Models\Horse;
use App\Models\Jockey;
use App\Models\Race;
use App\Models\RaceResult;
use App\Models\Trainer;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

/**
 * CSVインポートサービス
 *
 * 期待するCSV形式（1行目はヘッダ）:
 *   race_date, venue_code, race_number, race_name, grade, track_type, distance,
 *   course_condition, finish_position, frame_number, horse_number, horse_name,
 *   sex, age, weight_carried, jockey_name, trainer_name, time, margin,
 *   last_3f, corner_positions, popularity, win_odds, horse_weight, horse_weight_diff
 *
 * 同じレースの馬は連続して並べてください。
 */
class CsvImportService
{
    public function import(string $filePath): array
    {
        $reader = Reader::createFromPath($filePath, 'r');
        $reader->setHeaderOffset(0);

        $stats = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0];
        $racesCache = [];

        DB::beginTransaction();
        try {
            foreach ($reader->getRecords() as $row) {
                $stats['total']++;
                try {
                    $venueCode = trim((string) ($row['venue_code'] ?? ''));
                    $raceDate = trim((string) ($row['race_date'] ?? ''));
                    $raceNumber = (int) ($row['race_number'] ?? 0);

                    if (!$venueCode || !$raceDate || !$raceNumber) {
                        $stats['skipped']++;
                        continue;
                    }

                    $venue = Venue::where('code', str_pad($venueCode, 2, '0', STR_PAD_LEFT))->first();
                    if (!$venue) {
                        $stats['failed']++;
                        continue;
                    }

                    $cacheKey = "{$raceDate}:{$venue->id}:{$raceNumber}";
                    if (!isset($racesCache[$cacheKey])) {
                        $racesCache[$cacheKey] = Race::firstOrCreate(
                            [
                                'venue_id' => $venue->id,
                                'race_date' => $raceDate,
                                'race_number' => $raceNumber,
                            ],
                            [
                                'name' => $row['race_name'] ?? "{$venue->name} {$raceNumber}R",
                                'grade' => $row['grade'] ?? null,
                                'track_type' => $row['track_type'] ?? '芝',
                                'distance' => (int) ($row['distance'] ?? 1600),
                                'course_condition' => $row['course_condition'] ?? null,
                            ]
                        );
                    }
                    $race = $racesCache[$cacheKey];

                    if (empty($row['horse_name'])) {
                        $stats['skipped']++;
                        continue;
                    }

                    $horse = Horse::firstOrCreate(
                        ['name' => trim((string) $row['horse_name'])],
                        ['sex' => $row['sex'] ?? null]
                    );

                    $jockey = !empty($row['jockey_name'])
                        ? Jockey::firstOrCreate(['name' => trim((string) $row['jockey_name'])])
                        : null;

                    $trainer = !empty($row['trainer_name'])
                        ? Trainer::firstOrCreate(['name' => trim((string) $row['trainer_name'])])
                        : null;

                    $finishInt = is_numeric($row['finish_position'] ?? null)
                        ? (int) $row['finish_position']
                        : null;

                    $timeSeconds = null;
                    if (!empty($row['time']) && preg_match('/^(\d+):(\d+\.?\d*)$/', $row['time'], $m)) {
                        $timeSeconds = (int) $m[1] * 60 + (float) $m[2];
                    }

                    $runningStyle = RaceResult::detectRunningStyle(
                        $row['corner_positions'] ?? null,
                        $race->horses_count
                    );

                    RaceResult::updateOrCreate(
                        [
                            'race_id' => $race->id,
                            'horse_number' => (int) ($row['horse_number'] ?? 0),
                        ],
                        [
                            'horse_id' => $horse->id,
                            'jockey_id' => $jockey?->id,
                            'trainer_id' => $trainer?->id,
                            'finish_position' => $row['finish_position'] ?? null,
                            'finish_position_int' => $finishInt,
                            'frame_number' => $row['frame_number'] ?? null,
                            'sex' => $row['sex'] ?? null,
                            'age' => $row['age'] ?? null,
                            'weight_carried' => $row['weight_carried'] ?? null,
                            'horse_weight' => $row['horse_weight'] ?? null,
                            'horse_weight_diff' => $row['horse_weight_diff'] ?? null,
                            'time' => $row['time'] ?? null,
                            'time_seconds' => $timeSeconds,
                            'margin' => $row['margin'] ?? null,
                            'last_3f' => $row['last_3f'] ?? null,
                            'last_3f_seconds' => is_numeric($row['last_3f'] ?? null) ? (float) $row['last_3f'] : null,
                            'corner_positions' => $row['corner_positions'] ?? null,
                            'running_style' => $runningStyle,
                            'popularity' => $row['popularity'] ?? null,
                            'win_odds' => $row['win_odds'] ?? null,
                        ]
                    );

                    $stats['imported']++;
                } catch (\Throwable $e) {
                    $stats['failed']++;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $stats;
    }
}
