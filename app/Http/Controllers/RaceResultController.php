<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\Jockey;
use App\Models\Race;
use App\Models\RaceResult;
use App\Models\Trainer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RaceResultController extends Controller
{
    public function store(Request $request, Race $race): RedirectResponse
    {
        $validated = $this->validateResult($request);

        DB::transaction(function () use ($validated, $race) {
            // 馬・騎手・調教師を必要に応じて作成
            $horse = Horse::firstOrCreate(
                ['name' => $validated['horse_name']],
                ['sex' => $validated['sex'] ?? null]
            );

            $jockey = !empty($validated['jockey_name'])
                ? Jockey::firstOrCreate(['name' => $validated['jockey_name']])
                : null;

            $trainer = !empty($validated['trainer_name'])
                ? Trainer::firstOrCreate(['name' => $validated['trainer_name']])
                : null;

            // 着順の数値変換
            $finishInt = is_numeric($validated['finish_position'] ?? null)
                ? (int) $validated['finish_position']
                : null;

            // タイム秒換算
            $timeSeconds = $this->parseTimeToSeconds($validated['time'] ?? null);

            // 上がり3F秒換算
            $last3fSeconds = !empty($validated['last_3f']) && is_numeric($validated['last_3f'])
                ? (float) $validated['last_3f']
                : null;

            // 脚質自動判定
            $runningStyle = $validated['running_style']
                ?? RaceResult::detectRunningStyle(
                    $validated['corner_positions'] ?? null,
                    $race->horses_count
                );

            RaceResult::create([
                'race_id' => $race->id,
                'horse_id' => $horse->id,
                'jockey_id' => $jockey?->id,
                'trainer_id' => $trainer?->id,
                'finish_position' => $validated['finish_position'] ?? null,
                'finish_position_int' => $finishInt,
                'frame_number' => $validated['frame_number'] ?? null,
                'horse_number' => $validated['horse_number'],
                'sex' => $validated['sex'] ?? null,
                'age' => $validated['age'] ?? null,
                'weight_carried' => $validated['weight_carried'] ?? null,
                'horse_weight' => $validated['horse_weight'] ?? null,
                'horse_weight_diff' => $validated['horse_weight_diff'] ?? null,
                'time' => $validated['time'] ?? null,
                'time_seconds' => $timeSeconds,
                'margin' => $validated['margin'] ?? null,
                'last_3f' => $validated['last_3f'] ?? null,
                'last_3f_seconds' => $last3fSeconds,
                'corner_positions' => $validated['corner_positions'] ?? null,
                'running_style' => $runningStyle,
                'popularity' => $validated['popularity'] ?? null,
                'win_odds' => $validated['win_odds'] ?? null,
                'prize_money' => $validated['prize_money'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return redirect()->route('races.show', $race)
            ->with('status', '出走馬の結果を追加しました');
    }

    public function update(Request $request, Race $race, RaceResult $result): RedirectResponse
    {
        $validated = $this->validateResult($request);

        $finishInt = is_numeric($validated['finish_position'] ?? null)
            ? (int) $validated['finish_position']
            : null;

        $result->update([
            'finish_position' => $validated['finish_position'] ?? null,
            'finish_position_int' => $finishInt,
            'frame_number' => $validated['frame_number'] ?? null,
            'horse_number' => $validated['horse_number'],
            'sex' => $validated['sex'] ?? null,
            'age' => $validated['age'] ?? null,
            'weight_carried' => $validated['weight_carried'] ?? null,
            'horse_weight' => $validated['horse_weight'] ?? null,
            'horse_weight_diff' => $validated['horse_weight_diff'] ?? null,
            'time' => $validated['time'] ?? null,
            'time_seconds' => $this->parseTimeToSeconds($validated['time'] ?? null),
            'margin' => $validated['margin'] ?? null,
            'last_3f' => $validated['last_3f'] ?? null,
            'last_3f_seconds' => is_numeric($validated['last_3f'] ?? null) ? (float) $validated['last_3f'] : null,
            'corner_positions' => $validated['corner_positions'] ?? null,
            'running_style' => $validated['running_style'] ?? null,
            'popularity' => $validated['popularity'] ?? null,
            'win_odds' => $validated['win_odds'] ?? null,
            'prize_money' => $validated['prize_money'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('races.show', $race)->with('status', '結果を更新しました');
    }

    public function destroy(Race $race, RaceResult $result): RedirectResponse
    {
        $result->delete();
        return redirect()->route('races.show', $race)->with('status', '結果を削除しました');
    }

    private function validateResult(Request $request): array
    {
        return $request->validate([
            'horse_name' => ['required', 'string', 'max:50'],
            'jockey_name' => ['nullable', 'string', 'max:50'],
            'trainer_name' => ['nullable', 'string', 'max:50'],
            'finish_position' => ['nullable', 'string', 'max:5'],
            'frame_number' => ['nullable', 'integer', 'min:1', 'max:8'],
            'horse_number' => ['required', 'integer', 'min:1', 'max:18'],
            'sex' => ['nullable', 'in:牡,牝,セ'],
            'age' => ['nullable', 'integer', 'min:2', 'max:12'],
            'weight_carried' => ['nullable', 'numeric', 'min:30', 'max:70'],
            'horse_weight' => ['nullable', 'integer', 'min:300', 'max:700'],
            'horse_weight_diff' => ['nullable', 'integer', 'min:-50', 'max:50'],
            'time' => ['nullable', 'string', 'max:10'],
            'margin' => ['nullable', 'string', 'max:10'],
            'last_3f' => ['nullable', 'string', 'max:6'],
            'corner_positions' => ['nullable', 'string', 'max:30'],
            'running_style' => ['nullable', 'string', 'max:10'],
            'popularity' => ['nullable', 'integer', 'min:1', 'max:18'],
            'win_odds' => ['nullable', 'numeric', 'min:1'],
            'prize_money' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    /**
     * "1:23.4" → 83.4 秒に変換
     */
    private function parseTimeToSeconds(?string $time): ?float
    {
        if (!$time) return null;
        if (preg_match('/^(\d+):(\d+\.?\d*)$/', $time, $m)) {
            return (int) $m[1] * 60 + (float) $m[2];
        }
        if (is_numeric($time)) {
            return (float) $time;
        }
        return null;
    }
}
