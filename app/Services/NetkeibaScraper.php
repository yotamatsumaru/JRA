<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * netkeiba.com からレース結果をスクレイピング
 *
 * ⚠️ 注意:
 *  - 個人利用・私的範囲のみ
 *  - 連続アクセス時は必ずインターバル(5秒以上)を挟む
 *  - 取得データの再配布は禁止
 */
class NetkeibaScraper
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('services.netkeiba.base_url'),
            'timeout' => config('services.netkeiba.timeout', 30),
            'headers' => [
                'User-Agent' => config('services.netkeiba.user_agent'),
                'Accept-Language' => 'ja,en;q=0.8',
            ],
        ]);
    }

    /**
     * race_id (12桁) からレース結果を取得
     *
     * @param string $raceId 例: "202405021211" (年4桁+場2桁+回2桁+日2桁+R2桁)
     * @return array
     */
    public function fetchRace(string $raceId): array
    {
        $this->respectInterval();

        $url = "/race/{$raceId}/";
        Log::info("Netkeiba fetch: {$url}");

        $response = $this->http->get($url);
        $html = $response->getBody()->getContents();
        $html = mb_convert_encoding($html, 'UTF-8', 'EUC-JP, UTF-8, SJIS, JIS, ASCII');

        return $this->parseRaceHtml($raceId, $html);
    }

    /**
     * 開催日からレースID一覧を取得
     *
     * @param string $date YYYY-MM-DD
     * @return array race_idのリスト
     */
    public function fetchRaceIdsByDate(string $date): array
    {
        $this->respectInterval();

        $ymd = str_replace('-', '', $date);
        $url = "/race/list/{$ymd}/";

        $response = $this->http->get($url);
        $html = $response->getBody()->getContents();
        $html = mb_convert_encoding($html, 'UTF-8', 'EUC-JP, UTF-8, SJIS, JIS, ASCII');

        $crawler = new Crawler($html);
        $ids = [];
        $crawler->filter('a[href*="/race/"]')->each(function ($node) use (&$ids) {
            $href = $node->attr('href');
            if ($href && preg_match('|/race/(\d{12})/?|', $href, $m)) {
                $ids[$m[1]] = true;
            }
        });

        return array_keys($ids);
    }

    /**
     * リクエスト間隔を空ける
     */
    protected function respectInterval(): void
    {
        $last = Cache::get('netkeiba_last_request', 0);
        $interval = (int) config('services.netkeiba.request_interval', 5);
        $diff = time() - $last;
        if ($diff < $interval) {
            sleep($interval - $diff);
        }
        Cache::put('netkeiba_last_request', time(), now()->addMinutes(10));
    }

    /**
     * netkeibaのHTMLをパースして構造化データに変換
     */
    protected function parseRaceHtml(string $raceId, string $html): array
    {
        $crawler = new Crawler($html);
        $data = ['netkeiba_id' => $raceId];

        // race_id の構造から開催日・場を逆算
        $year = substr($raceId, 0, 4);
        $venueCode = substr($raceId, 4, 2);
        $kaisaiKai = (int) substr($raceId, 6, 2);
        $kaisaiDay = (int) substr($raceId, 8, 2);
        $raceNumber = (int) substr($raceId, 10, 2);

        $data['venue_code'] = $venueCode;
        $data['kaisai_kai'] = $kaisaiKai;
        $data['kaisai_day'] = $kaisaiDay;
        $data['race_number'] = $raceNumber;

        // レース名
        try {
            $data['name'] = trim($crawler->filter('.data_intro h1, .race_title')->first()->text(''));
        } catch (\Throwable $e) {
            $data['name'] = "Race {$raceId}";
        }

        // レース詳細(距離・馬場・天候)
        try {
            $detailText = $crawler->filter('.data_intro .smalltxt, .data_intro span')->text('');
            if (preg_match('/(\d+)月(\d+)日/u', $detailText, $m)) {
                $data['race_date'] = sprintf('%s-%02d-%02d', $year, $m[1], $m[2]);
            }
        } catch (\Throwable $e) {}

        try {
            $diary = $crawler->filter('.data_intro diary_snap_cut, .data_intro')->text('');
            if (preg_match('/(芝|ダ|障)\s*(右|左|直)?\s*(\d+)m/u', $diary, $m)) {
                $data['track_type'] = ['芝' => '芝', 'ダ' => 'ダート', '障' => '障害'][$m[1]] ?? $m[1];
                $data['direction'] = $m[2] ?: null;
                $data['distance'] = (int) $m[3];
            }
            if (preg_match('/天候\s*:\s*(\S+)/u', $diary, $m)) {
                $data['weather'] = trim($m[1]);
            }
            if (preg_match('/馬場\s*:\s*(\S+)/u', $diary, $m)) {
                $data['course_condition'] = trim($m[1]);
            }
        } catch (\Throwable $e) {}

        // 出走馬テーブル
        $results = [];
        try {
            $crawler->filter('table.race_table_01 tr, table.race_table_old tr')
                ->each(function (Crawler $tr, $i) use (&$results) {
                    if ($i === 0) return; // ヘッダ
                    $tds = $tr->filter('td');
                    if ($tds->count() < 10) return;

                    $row = [];
                    $row['finish_position'] = trim($tds->eq(0)->text(''));
                    $row['frame_number'] = (int) trim($tds->eq(1)->text(''));
                    $row['horse_number'] = (int) trim($tds->eq(2)->text(''));

                    try {
                        $row['horse_name'] = trim($tds->eq(3)->filter('a')->first()->text(''));
                    } catch (\Throwable $e) {
                        $row['horse_name'] = trim($tds->eq(3)->text(''));
                    }

                    $sexAge = trim($tds->eq(4)->text(''));
                    if (preg_match('/(牡|牝|セ)(\d+)/u', $sexAge, $m)) {
                        $row['sex'] = $m[1];
                        $row['age'] = (int) $m[2];
                    }

                    $row['weight_carried'] = (float) trim($tds->eq(5)->text(''));

                    try {
                        $row['jockey_name'] = trim($tds->eq(6)->filter('a')->first()->text(''));
                    } catch (\Throwable $e) {
                        $row['jockey_name'] = trim($tds->eq(6)->text(''));
                    }

                    $row['time'] = trim($tds->eq(7)->text(''));
                    $row['margin'] = trim($tds->eq(8)->text(''));

                    if ($tds->count() > 14) {
                        $row['popularity'] = (int) trim($tds->eq(13)->text(''));
                        $row['win_odds'] = (float) trim($tds->eq(12)->text(''));
                    }

                    $results[] = $row;
                });
        } catch (\Throwable $e) {
            Log::warning("Netkeiba parse error for race {$raceId}: " . $e->getMessage());
        }

        $data['results'] = $results;
        $data['horses_count'] = count($results);

        return $data;
    }
}
