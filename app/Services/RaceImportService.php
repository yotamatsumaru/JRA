<?php

namespace App\Services;

use App\Http\Controllers\DashboardController;
use App\Models\Horse;
use App\Models\Jockey;
use App\Models\Payout;
use App\Models\Race;
use App\Models\RaceResult;
use App\Models\Trainer;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * レースデータ取込の共通サービス
 * netkeibaスクレイピング・画像解析・CSVから来たデータを統一的にDBに保存
 */
class RaceImportService
{
    /**
     * 取込中に出走馬の血統を同期取得するか
     * false (デフォルト) なら血統補完はスキップし、後から netkeiba:fill-pedigree で一括補完する。
     * netkeiba:year / netkeiba:date など大量取込で1レースあたり馬数分の追加HTTPが発生して激重になるため。
     */
    protected bool $fetchPedigreeOnImport = false;

    /**
     * 血統同期取得モードを切替
     */
    public function setFetchPedigreeOnImport(bool $enabled): void
    {
        $this->fetchPedigreeOnImport = $enabled;
    }

    /**
     * netkeibaから取得した整形済データをDBに保存
     *
     * デッドロック対策: DB::transaction の第2引数で 3 回まで自動リトライ。
     * 大量並列取込で horses/jockeys/trainers の firstOrCreate がギャップロックを
     * 取り合って SQLSTATE 40001 (Deadlock) が発生することがあるが、Laravel が
     * 検出して自動的にトランザクション全体を再実行してくれる。
     */
    public function importFromNetkeiba(array $data): Race
    {
        return DB::transaction(function () use ($data) {
            // 競馬場
            $venue = Venue::where('code', $data['venue_code'] ?? null)->first();
            if (!$venue) {
                throw new \RuntimeException('対応する競馬場が見つかりません: ' . ($data['venue_code'] ?? 'null'));
            }

            // レース本体
            $race = Race::updateOrCreate(
                ['netkeiba_id' => $data['netkeiba_id']],
                [
                    'venue_id' => $venue->id,
                    'race_date' => $data['race_date'] ?? now()->toDateString(),
                    'kaisai_kai' => $data['kaisai_kai'] ?? null,
                    'kaisai_day' => $data['kaisai_day'] ?? null,
                    'race_number' => $data['race_number'] ?? 1,
                    'name' => $data['name'] ?? 'Unknown',
                    'grade' => $data['grade'] ?? null,
                    'track_type' => $data['track_type'] ?? '芝',
                    'distance' => $data['distance'] ?? 1600,
                    'direction' => $data['direction'] ?? null,
                    'course_condition' => $data['course_condition'] ?? null,
                    'weather' => $data['weather'] ?? null,
                    'horses_count' => $data['horses_count'] ?? null,
                    'first_3f' => $data['first_3f'] ?? null,
                    'last_3f' => $data['last_3f'] ?? null,
                    'lap_times' => $data['lap_times'] ?? null,
                ]
            );

            // 既存結果を削除して再投入
            $race->results()->delete();

            foreach ($data['results'] ?? [] as $row) {
                $this->createResult($race, $row);
            }

            // ============ ペース自動判定 ============
            // 結果保存後に通過順データから H/M/S を判定して races.pace に保存
            $this->recalcPace($race);

            // ============ 払戻データ ============
            $this->savePayouts($race, $data['payouts'] ?? []);

            // ============ 馬券の自動精算 ============
            $this->autoSettleBets($race);

            // ============ ダッシュボード集計キャッシュを破棄 ============
            // 結果が変わったので 24h キャッシュを無効化して次表示時に再計算させる
            DashboardController::flushAggregatesCache();

            return $race;
        }, 3); // ★ デッドロック時に最大3回まで自動再試行
    }

    /**
     * netkeiba から取得した出馬表データを DB に保存（レース確定前）
     *
     * importFromNetkeiba との違い:
     *   - 既存の race_results を削除せず、(race_id, horse_number) で UPSERT
     *     → レース後に importFromNetkeiba を再実行すると同じ行が結果で上書きされる
     *   - finish_position / time / margin / corner_positions / last_3f は null のまま
     *   - 払戻データは無いのでスキップ
     *   - 馬券の自動精算もスキップ（結果が無いため）
     *   - races のフィールドは出馬表で確定している項目のみ更新
     */
    public function importShutuba(array $data): Race
    {
        return DB::transaction(function () use ($data) {
            // 競馬場
            $venue = Venue::where('code', $data['venue_code'] ?? null)->first();
            if (!$venue) {
                throw new \RuntimeException('対応する競馬場が見つかりません: ' . ($data['venue_code'] ?? 'null'));
            }

            // レース本体（updateOrCreate だが、結果由来フィールドは更新しない）
            $race = Race::updateOrCreate(
                ['netkeiba_id' => $data['netkeiba_id']],
                array_filter([
                    'venue_id'         => $venue->id,
                    'race_date'        => $data['race_date'] ?? now()->toDateString(),
                    'kaisai_kai'       => $data['kaisai_kai'] ?? null,
                    'kaisai_day'       => $data['kaisai_day'] ?? null,
                    'race_number'      => $data['race_number'] ?? 1,
                    'name'             => $data['name'] ?? 'Unknown',
                    'grade'            => $data['grade'] ?? null,
                    'track_type'       => $data['track_type'] ?? '芝',
                    'distance'         => $data['distance'] ?? 1600,
                    'direction'        => $data['direction'] ?? null,
                    'course_condition' => $data['course_condition'] ?? null,
                    'weather'          => $data['weather'] ?? null,
                    'horses_count'     => $data['horses_count'] ?? null,
                ], fn($v) => $v !== null),
            );

            // 出馬表行を UPSERT（既存結果はそのまま温存）
            foreach ($data['results'] ?? [] as $row) {
                $this->upsertShutubaResult($race, $row);
            }

            return $race;
        }, 3); // ★ デッドロック時に最大3回まで自動再試行
    }

    /**
     * 出馬表段階の RaceResult を (race_id, horse_number) キーで UPSERT
     *
     * 既に同じ (race_id, horse_number) の行が存在する場合（=結果取込済み）は
     * 結果系フィールド (finish_position / time 等) を絶対に上書きしない。
     * 出馬表でしか拾えない静的フィールド（騎手・斤量・性齢・厩舎）のみ補完する。
     */
    protected function upsertShutubaResult(Race $race, array $row): RaceResult
    {
        $horse   = $this->resolveHorse($row);
        $jockey  = $this->resolveJockey($row);
        $trainer = $this->resolveTrainer($row);

        $horseNumber = (int) ($row['horse_number'] ?? 0);
        if ($horseNumber <= 0) {
            throw new \RuntimeException('出馬表行に馬番がありません: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
        }

        // 既存行があれば取得（UNIQUE(race_id, horse_number)）
        $existing = RaceResult::where('race_id', $race->id)
            ->where('horse_number', $horseNumber)
            ->first();

        // 出馬表で取得できる静的属性（結果由来フィールドは触らない）
        $payload = [
            'race_id'        => $race->id,
            'horse_id'       => $horse->id,
            'jockey_id'      => $jockey?->id,
            'trainer_id'     => $trainer?->id,
            'horse_number'   => $horseNumber,
            'frame_number'   => $row['frame_number'] ?? null,
            'sex'            => $row['sex'] ?? null,
            'age'            => $row['age'] ?? null,
            'weight_carried' => $row['weight_carried'] ?? null,
            // 馬体重は出馬表段階では当日計測前のことも多いが、取れた場合のみ反映
            'horse_weight'      => $row['horse_weight'] ?? null,
            'horse_weight_diff' => $row['horse_weight_diff'] ?? null,
            'popularity'        => $row['popularity'] ?? null,
            'win_odds'          => $row['win_odds'] ?? null,
        ];

        if ($existing) {
            // 既存行: 値が空のフィールドのみ補完（結果取込済みデータを破壊しない）
            foreach ($payload as $key => $value) {
                if ($value === null) continue;
                if ($key === 'race_id' || $key === 'horse_number') continue;
                if (empty($existing->{$key})) {
                    $existing->{$key} = $value;
                }
            }
            // 馬・騎手・調教師のIDが変わっていれば必ず更新（同馬番でも異なる馬の登録漏れを防ぐ）
            $existing->horse_id   = $horse->id;
            if ($jockey)  $existing->jockey_id  = $jockey->id;
            if ($trainer) $existing->trainer_id = $trainer->id;
            if ($existing->isDirty()) $existing->save();
            return $existing;
        }

        // 新規行: 結果系フィールドは明示的に null
        $payload['finish_position']     = null;
        $payload['finish_position_int'] = null;
        $payload['time']                = null;
        $payload['time_seconds']        = null;
        $payload['margin']              = null;
        $payload['last_3f']             = null;
        $payload['last_3f_seconds']     = null;
        $payload['corner_positions']    = null;
        $payload['running_style']       = null;

        return RaceResult::create($payload);
    }

    /**
     * レース結果の通過順位から races.pace を再計算して保存
     */
    public function recalcPace(Race $race): ?string
    {
        try {
            $results = $race->results()->select(['corner_positions'])->get();
            $pace = Race::detectPace(
                $results,
                $race->horses_count,
                $race->first_3f,
                $race->last_3f
            );
            if ($pace !== null && $pace !== $race->pace) {
                $race->pace = $pace;
                $race->save();
            }
            return $pace;
        } catch (\Throwable $e) {
            Log::warning('ペース判定失敗', [
                'race_id' => $race->id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 払戻データを保存（既存はクリアして再投入）
     */
    protected function savePayouts(Race $race, array $payouts): void
    {
        $race->payouts()->delete();

        foreach ($payouts as $p) {
            if (empty($p['kind']) || empty($p['combination']) || !isset($p['amount'])) {
                continue;
            }
            try {
                Payout::create([
                    'race_id'     => $race->id,
                    'kind'        => $p['kind'],
                    'combination' => $p['combination'],
                    'amount'      => (int) $p['amount'],
                    'popularity'  => isset($p['popularity']) && is_numeric($p['popularity'])
                        ? (int) $p['popularity']
                        : null,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Payout保存失敗', [
                    'race_id' => $race->id,
                    'kind'    => $p['kind'] ?? null,
                    'combo'   => $p['combination'] ?? null,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * このレースに紐づく未確定の馬券を自動精算
     * 結果と払戻が揃ったタイミングで呼ばれる
     */
    protected function autoSettleBets(Race $race): void
    {
        // 結果が無い場合は精算しない
        if ($race->results()->count() === 0) {
            return;
        }

        try {
            $service = app(BetTicketService::class);
            $bets = $race->bets()->with('legs')->get();
            foreach ($bets as $bet) {
                $service->settle($bet);
            }
        } catch (\Throwable $e) {
            Log::warning('馬券の自動精算に失敗', [
                'race_id' => $race->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * 画像解析(Vision API)から取得したデータをDBに保存
     */
    public function importFromImage(array $data): Race
    {
        return DB::transaction(function () use ($data) {
            $raceData = $data['race'] ?? [];

            // 競馬場名から検索
            $venue = !empty($raceData['venue_name'])
                ? Venue::where('name', $raceData['venue_name'])->first()
                : null;
            if (!$venue) {
                throw new \RuntimeException('競馬場名が認識できません: ' . ($raceData['venue_name'] ?? 'null'));
            }

            $race = Race::create([
                'venue_id' => $venue->id,
                'race_date' => $raceData['race_date'] ?? now()->toDateString(),
                'race_number' => $raceData['race_number'] ?? 1,
                'name' => $raceData['name'] ?? 'Imported Race',
                'grade' => $raceData['grade'] ?? null,
                'track_type' => $raceData['track_type'] ?? '芝',
                'distance' => $raceData['distance'] ?? 1600,
                'course_condition' => $raceData['course_condition'] ?? null,
                'weather' => $raceData['weather'] ?? null,
                'horses_count' => count($data['results'] ?? []),
            ]);

            foreach ($data['results'] ?? [] as $row) {
                $this->createResult($race, $row);
            }

            // ダッシュボード集計キャッシュを破棄 (画像取込でも結果が増えるため)
            DashboardController::flushAggregatesCache();

            return $race;
        }, 3); // ★ デッドロック時に最大3回まで自動再試行
    }

    /**
     * RaceResult を作成（馬・騎手・調教師は自動作成）
     *
     * 馬は netkeiba_id があればそちらを優先キーに、無ければ name で firstOrCreate。
     * 血統(父/母/母父)が未取得なら netkeiba から自動取得して補完する。
     */
    protected function createResult(Race $race, array $row): RaceResult
    {
        $horse = $this->resolveHorse($row);
        $jockey = $this->resolveJockey($row);
        $trainer = $this->resolveTrainer($row);

        $finishInt = is_numeric($row['finish_position'] ?? null)
            ? (int) $row['finish_position']
            : null;

        $timeSeconds = $this->parseTimeToSeconds($row['time'] ?? null);

        $runningStyle = RaceResult::detectRunningStyle(
            $row['corner_positions'] ?? null,
            $race->horses_count
        );

        return RaceResult::create([
            'race_id' => $race->id,
            'horse_id' => $horse->id,
            'jockey_id' => $jockey?->id,
            'trainer_id' => $trainer?->id,
            'finish_position' => $row['finish_position'] ?? null,
            'finish_position_int' => $finishInt,
            'frame_number' => $row['frame_number'] ?? null,
            'horse_number' => $row['horse_number'] ?? 0,
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
        ]);
    }

    protected function parseTimeToSeconds(?string $time): ?float
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

    /**
     * 馬を解決（netkeiba_id があればそれをキーに、無ければ name）
     * 血統が未取得かつ netkeiba_id があれば自動取得して補完
     */
    protected function resolveHorse(array $row): Horse
    {
        $name = $row['horse_name'] ?? 'Unknown';
        $netkeibaId = $row['horse_netkeiba_id'] ?? null;

        if ($netkeibaId) {
            $horse = Horse::firstOrCreate(
                ['netkeiba_id' => $netkeibaId],
                [
                    'name' => $name,
                    'sex'  => $row['sex'] ?? null,
                ]
            );

            // 名前が変わってたら更新（出走時馬名は不変なので通常一致）
            if ($horse->name !== $name && $name !== 'Unknown') {
                $horse->name = $name;
            }
            if (empty($horse->sex) && !empty($row['sex'])) {
                $horse->sex = $row['sex'];
            }
            if ($horse->isDirty()) $horse->save();

            // 血統が空なら netkeiba から取得して補完
            // ※ 大量取込時(netkeiba:year 等)では出走馬数分の追加HTTPが発生して激重になるため
            //   デフォルトではスキップ。後から netkeiba:fill-pedigree で一括補完する。
            if ($this->fetchPedigreeOnImport
                && (empty($horse->father) || empty($horse->mother))) {
                $this->fillHorsePedigree($horse);
            }

            return $horse;
        }

        // netkeiba_id 無し → name で解決
        $horse = Horse::firstOrCreate(
            ['name' => $name],
            ['sex' => $row['sex'] ?? null]
        );
        return $horse;
    }

    /**
     * 馬の血統情報を netkeiba から取得して補完
     * 失敗しても取込全体は止めない（ログのみ残す）
     */
    public function fillHorsePedigree(Horse $horse): bool
    {
        if (empty($horse->netkeiba_id)) {
            return false;
        }

        try {
            $scraper = app(NetkeibaScraper::class);
            $info = $scraper->fetchHorse($horse->netkeiba_id);

            $updated = false;
            foreach (['father', 'mother', 'mother_father', 'color', 'birthday', 'owner', 'breeder', 'birth_place', 'sex'] as $key) {
                if (!empty($info[$key]) && empty($horse->{$key})) {
                    $horse->{$key} = $info[$key];
                    $updated = true;
                }
            }
            if ($updated) {
                $horse->save();
                Log::info("Horse pedigree filled: {$horse->name} ({$horse->netkeiba_id})");
            }
            return $updated;
        } catch (\Throwable $e) {
            Log::warning('血統取得失敗', [
                'horse_id'    => $horse->id,
                'netkeiba_id' => $horse->netkeiba_id,
                'error'       => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function resolveJockey(array $row): ?Jockey
    {
        if (empty($row['jockey_name'])) return null;

        $netkeibaId = $row['jockey_netkeiba_id'] ?? null;
        if ($netkeibaId) {
            return Jockey::firstOrCreate(
                ['netkeiba_id' => $netkeibaId],
                ['name' => $row['jockey_name']]
            );
        }
        return Jockey::firstOrCreate(['name' => $row['jockey_name']]);
    }

    protected function resolveTrainer(array $row): ?Trainer
    {
        if (empty($row['trainer_name'])) return null;

        $netkeibaId = $row['trainer_netkeiba_id'] ?? null;
        if ($netkeibaId) {
            return Trainer::firstOrCreate(
                ['netkeiba_id' => $netkeibaId],
                ['name' => $row['trainer_name']]
            );
        }
        return Trainer::firstOrCreate(['name' => $row['trainer_name']]);
    }
}
