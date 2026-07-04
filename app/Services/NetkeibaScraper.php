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
 *
 * 対象URL: https://db.netkeiba.com/race/{race_id}/
 *  - race_id (12桁): YYYY + 場コード(2) + 開催回(2) + 開催日(2) + R(2)
 */
class NetkeibaScraper
{
    protected Client $http;

    /** リクエストインターバル(秒)。null の場合 config を参照 */
    protected ?int $intervalOverride = null;

    /** race.netkeiba.com 用の HTTP クライアント (フォールバック先) */
    protected Client $httpRace;

    public function __construct()
    {
        $headers = [
            'User-Agent' => config('services.netkeiba.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'ja,en;q=0.8',
        ];

        // 任意 Cookie (会員ログイン Cookie 等) があればヘッダに付与
        $cookie = config('services.netkeiba.cookie');
        if (is_string($cookie) && trim($cookie) !== '') {
            $headers['Cookie'] = trim($cookie);
        }

        // プロキシ設定を組み立て
        $clientOptions = [
            'timeout' => config('services.netkeiba.timeout', 30),
            'headers' => $headers,
            'http_errors' => false,
        ];

        $proxyConfig = $this->buildProxyConfig();
        if ($proxyConfig !== null) {
            $clientOptions['proxy'] = $proxyConfig;
            // プロキシが自己署名証明書を使う場合は verify=false に
            $clientOptions['verify'] = (bool) config('services.netkeiba.proxy_verify', true);
            Log::info('NetkeibaScraper: proxy enabled', [
                'proxy' => $this->maskProxy(is_array($proxyConfig) ? ($proxyConfig['http'] ?? '') : $proxyConfig),
                'verify' => $clientOptions['verify'],
            ]);
        }

        $this->http = new Client(array_merge($clientOptions, [
            'base_uri' => config('services.netkeiba.base_url'),
        ]));

        // race.netkeiba.com (新DB) フォールバック用
        $this->httpRace = new Client(array_merge($clientOptions, [
            'base_uri' => 'https://race.netkeiba.com',
        ]));
    }

    /**
     * env / config からプロキシ設定を構築。
     * 未設定なら null を返す（=プロキシ未使用、直結）。
     *
     * @return string|array|null
     */
    protected function buildProxyConfig()
    {
        $proxy      = config('services.netkeiba.proxy');
        $proxyHttps = config('services.netkeiba.proxy_https');
        $proxyNo    = config('services.netkeiba.proxy_no');

        if (empty($proxy) && empty($proxyHttps)) {
            return null;
        }

        // https専用や除外ホストの指定がない場合は単純な文字列で返す
        if (!empty($proxy) && empty($proxyHttps) && empty($proxyNo)) {
            return $proxy;
        }

        $config = [];
        if (!empty($proxy)) {
            $config['http'] = $proxy;
            // proxy_https 未指定なら http と共用
            $config['https'] = !empty($proxyHttps) ? $proxyHttps : $proxy;
        } elseif (!empty($proxyHttps)) {
            $config['https'] = $proxyHttps;
        }

        if (!empty($proxyNo)) {
            $config['no'] = array_values(array_filter(array_map('trim', explode(',', $proxyNo))));
        }

        return $config;
    }

    /**
     * ログ出力用にプロキシURLの user:pass 部分をマスク
     */
    protected function maskProxy(string $url): string
    {
        return preg_replace('#(://)([^:@/]+):([^@/]+)@#', '$1***:***@', $url) ?? $url;
    }

    /**
     * プロキシが設定されているか
     */
    public function isProxyEnabled(): bool
    {
        return $this->buildProxyConfig() !== null;
    }

    /**
     * リクエストインターバルをランタイムで上書き
     * 0 を渡すと完全に待機なし（自己責任）
     * null を渡すと config の値に戻す
     */
    public function setRequestInterval(?int $seconds): void
    {
        $this->intervalOverride = $seconds === null ? null : max(0, $seconds);
    }

    /**
     * 馬ID から血統・プロフィール情報を取得
     *
     * 取得項目:
     *  - father        父
     *  - mother        母
     *  - mother_father 母父
     *  - sex           性別 (牡/牝/セ)
     *  - color         毛色
     *  - birthday      生年月日 (YYYY-MM-DD)
     *  - owner         馬主
     *  - breeder       生産者
     *  - birth_place   産地
     *
     * @param string $horseId  netkeiba 馬ID（10桁）
     * @return array           取得できた項目のみ含むハッシュ
     */
    public function fetchHorse(string $horseId): array
    {
        $this->respectInterval();

        $url = "/horse/{$horseId}/";
        Log::info("Netkeiba fetch horse: {$url}");

        $response = $this->http->get($url);
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("netkeiba HTTP {$status} for horse_id={$horseId}");
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $html = $this->decodeHtml($response->getBody()->getContents(), $contentType);

        $data = $this->parseHorseHtml($horseId, $html);

        // 2025-07 以降のリニューアルで /horse/{id}/ には血統テーブルが
        // 直接埋め込まれず、AJAX エンドポイントから JSON で取得する仕様に
        // 変更された。そのため血統(父/母/母父)は別エンドポイントから補完する。
        try {
            $pedigree = $this->fetchPedigreeAjax($horseId);
            foreach (['father', 'mother', 'mother_father'] as $k) {
                if (!empty($pedigree[$k]) && empty($data[$k])) {
                    $data[$k] = $pedigree[$k];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Netkeiba: pedigree ajax fetch failed for horse_id={$horseId}: " . $e->getMessage());
        }

        return $data;
    }

    /**
     * 血統情報を AJAX エンドポイントから取得
     *
     * https://db.netkeiba.com/horse/ajax_horse_pedigree.html?id={id}&input=UTF-8&output=json
     * レスポンス: {"status":"OK","data":"<HTML断片>"}
     * data の中には旧来と同じ <table class="blood_table"> が含まれる。
     * 構造は rowspan を多用したマトリクスなので、td.b_ml(父系) / td.b_fml(母系)
     * のクラスを使って父/母/母父を堅く取得する。
     *
     * @param string $horseId
     * @return array  ['father' => ?, 'mother' => ?, 'mother_father' => ?]
     */
    protected function fetchPedigreeAjax(string $horseId): array
    {
        $this->respectInterval();

        Log::info("Netkeiba fetch pedigree ajax: horse_id={$horseId}");

        $response = $this->http->get('/horse/ajax_horse_pedigree.html', [
            'query' => [
                'id'     => $horseId,
                'input'  => 'UTF-8',
                'output' => 'json',
            ],
            'headers' => [
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer'          => "https://db.netkeiba.com/horse/{$horseId}/",
                'Accept'           => 'application/json, text/javascript, */*; q=0.01',
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("netkeiba pedigree HTTP {$status} for horse_id={$horseId}");
        }

        $body = $response->getBody()->getContents();
        $json = json_decode($body, true);
        if (!is_array($json) || (($json['status'] ?? '') !== 'OK') || empty($json['data'])) {
            Log::warning("Netkeiba: pedigree ajax invalid json for horse_id={$horseId}");
            return [];
        }

        $fragment = $json['data'];

        // 旧 blood_table (rowspan マトリクス) を classで判定して取得
        // 構造例:
        //   <tr><td rowspan=2 class=b_ml>父</td><td class=b_ml>父父</td></tr>
        //   <tr>                                <td class=b_fml>父母</td></tr>
        //   <tr><td rowspan=2 class=b_fml>母</td><td class=b_ml>母父</td></tr>
        //   <tr>                                <td class=b_fml>母母</td></tr>
        // → 1番目の b_ml(rowspan=2) = 父
        //   2番目の b_fml(rowspan=2) = 母
        //   2番目の b_ml             = 母父
        $result = [];
        try {
            $crawler = new Crawler($fragment);
            $bloodTable = $crawler->filter('table.blood_table')->first();
            if ($bloodTable->count() === 0) {
                Log::warning("Netkeiba: blood_table not found in pedigree ajax for horse_id={$horseId}");
                return [];
            }

            $bml = [];   // class b_ml の td
            $bfml = [];  // class b_fml の td
            $bloodTable->filter('td')->each(function (Crawler $td) use (&$bml, &$bfml) {
                $cls = $td->attr('class') ?? '';
                $a = $td->filter('a')->first();
                $text = $a->count() > 0 ? $this->cleanText($a->text('')) : $this->cleanText($td->text(''));
                if ($text === '') return;
                if (str_contains($cls, 'b_ml')) {
                    $bml[] = $text;
                } elseif (str_contains($cls, 'b_fml')) {
                    $bfml[] = $text;
                }
            });

            // 父 = b_ml 1番目, 母父 = b_ml 2番目, 母 = b_fml 1番目
            if (!empty($bml[0]))  $result['father']        = mb_substr($bml[0], 0, 50);
            if (!empty($bfml[0])) $result['mother']        = mb_substr($bfml[0], 0, 50);
            if (!empty($bml[1]))  $result['mother_father'] = mb_substr($bml[1], 0, 50);
        } catch (\Throwable $e) {
            Log::warning("Netkeiba: pedigree ajax parse failed for horse_id={$horseId}: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * 馬詳細ページのHTMLをパース
     */
    protected function parseHorseHtml(string $horseId, string $html): array
    {
        $html = preg_replace('/<!--.*?-->/su', '', $html);
        $crawler = new Crawler($html);

        $data = ['netkeiba_id' => $horseId];

        // ============ 馬名 ============
        $name = $this->extractText($crawler, '.horse_title h1, h1.horse_title, div.horse_title h1');
        if ($name) {
            $data['name'] = preg_replace('/\s+/u', ' ', trim($name));
        }

        // ============ 血統テーブル ============
        // 2025-07 以降、/horse/{id}/ には血統テーブルが直接埋め込まれず
        // AJAX エンドポイントから JSON で読み込む仕様に変更された。
        // 血統(父/母/母父)は fetchHorse() 側で fetchPedigreeAjax() を別途呼ぶ。

        // ============ プロフィールテーブル ============
        // <table class="db_prof_table"> 行ごとに <th>項目名</th><td>値</td>
        try {
            $profTable = $crawler->filter('table.db_prof_table')->first();
            if ($profTable->count() > 0) {
                $profTable->filter('tr')->each(function (Crawler $tr) use (&$data) {
                    try {
                        $th = $tr->filter('th')->first();
                        $td = $tr->filter('td')->first();
                        if ($th->count() === 0 || $td->count() === 0) return;
                        $label = $this->cleanText($th->text(''));
                        $value = $this->cleanText($td->text(''));
                        if ($value === '') return;

                        switch ($label) {
                            case '生年月日':
                                if (preg_match('/(\d{4})年(\d{1,2})月(\d{1,2})日/u', $value, $m)) {
                                    $data['birthday'] = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                                }
                                break;
                            case '毛色':
                                $data['color'] = mb_substr($value, 0, 20);
                                break;
                            case '馬主':
                                $data['owner'] = mb_substr($value, 0, 100);
                                break;
                            case '生産者':
                                $data['breeder'] = mb_substr($value, 0, 100);
                                break;
                            case '産地':
                                $data['birth_place'] = mb_substr($value, 0, 50);
                                break;
                        }
                    } catch (\Throwable $e) {}
                });
            }
        } catch (\Throwable $e) {
            Log::warning("Netkeiba: db_prof_table parse failed for horse_id={$horseId}: " . $e->getMessage());
        }

        // ============ 性別（タイトル付近に「牡」「牝」「セ」が出る） ============
        try {
            $titleText = $this->cleanText($crawler->filter('.horse_title, .db_main_data')->text(''));
            if (preg_match('/(牡|牝|セ)/u', $titleText, $m)) {
                $data['sex'] = $m[1];
            }
        } catch (\Throwable $e) {}

        return $data;
    }

    /**
     * race_id (12桁) からレース結果を取得
     *
     * 取得先:
     *   1) https://db.netkeiba.com/race/{id}/                              (旧DB; 古いレース向け)
     *   2) https://race.netkeiba.com/race/result.html?race_id={id}         (新DB; 最近のレース向け)
     * 1) が 200 以外を返した場合、自動で 2) にフォールバックする。
     */
    public function fetchRace(string $raceId, ?string $expectedDate = null): array
    {
        // ============ 1) db.netkeiba.com を試す ============
        $this->respectInterval();
        $dbUrl = "/race/{$raceId}/";
        Log::info("Netkeiba fetch (db): {$dbUrl}" . ($expectedDate ? " (expectedDate={$expectedDate})" : ''));

        $dbStatus = null;
        try {
            $response = $this->http->get($dbUrl);
            $dbStatus = $response->getStatusCode();
            if ($dbStatus === 200) {
                $contentType = $response->getHeaderLine('Content-Type');
                $html = $this->decodeHtml($response->getBody()->getContents(), $contentType);

                if (config('services.netkeiba.debug_save')) {
                    $path = storage_path("logs/netkeiba_{$raceId}_db.html");
                    @file_put_contents($path, $html);
                }

                $data = $this->parseRaceHtml($raceId, $html, $expectedDate);
                // db ページが返っても結果テーブルが空なら新DBにもチャレンジする
                if (!empty($data['results'])) {
                    return $data;
                }
                Log::info("Netkeiba: db page had no results, falling back to race.netkeiba.com for {$raceId}");
            } else {
                Log::info("Netkeiba: db returned HTTP {$dbStatus} for {$raceId}, falling back to race.netkeiba.com");
            }
        } catch (\Throwable $e) {
            Log::warning("Netkeiba: db fetch error for {$raceId}: " . $e->getMessage());
        }

        // ============ 2) race.netkeiba.com にフォールバック ============
        $this->respectInterval();
        $raceUrl = "/race/result.html?race_id={$raceId}";
        Log::info("Netkeiba fetch (race): {$raceUrl}");

        $response = $this->httpRace->get($raceUrl);
        $status = $response->getStatusCode();
        if ($status !== 200) {
            // 両方ダメなときだけ例外。db の最終ステータスも併記
            throw new \RuntimeException(
                "netkeiba HTTP {$status} (db={$dbStatus}) for race_id={$raceId}"
            );
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $html = $this->decodeHtml($response->getBody()->getContents(), $contentType);

        if (config('services.netkeiba.debug_save')) {
            $path = storage_path("logs/netkeiba_{$raceId}_race.html");
            @file_put_contents($path, $html);
        }

        return $this->parseRaceResultHtml($raceId, $html, $expectedDate);
    }

    /**
     * race_id (12桁) から出馬表を取得（レース確定前のエントリー情報）
     *
     * 取得先: https://race.netkeiba.com/race/shutuba.html?race_id={id}
     *
     * 取得項目:
     *   - レース基本情報 (name, race_date, track_type, distance, direction, weather, course_condition)
     *   - 出走馬一覧 (results) — 着順・タイム等は null
     *
     * 結果(fetchRace) と同じスキーマで返すため、importFromNetkeiba と互換的に取込可能。
     * 出馬表段階では当然ながら finish_position / time / margin / corner_positions / last_3f /
     * horse_weight / horse_weight_diff は null となる（レース後に上書きされる）。
     *
     * @param string $raceId
     * @return array
     */
    public function fetchShutuba(string $raceId, ?string $expectedDate = null): array
    {
        $this->respectInterval();
        $url = "/race/shutuba.html?race_id={$raceId}";
        Log::info("Netkeiba fetch shutuba: {$url}" . ($expectedDate ? " (expectedDate={$expectedDate})" : ''));

        $response = $this->httpRace->get($url);
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("netkeiba shutuba HTTP {$status} for race_id={$raceId}");
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $html = $this->decodeHtml($response->getBody()->getContents(), $contentType);

        if (config('services.netkeiba.debug_save')) {
            $path = storage_path("logs/netkeiba_{$raceId}_shutuba.html");
            @file_put_contents($path, $html);
        }

        $data = $this->parseShutubaHtml($raceId, $html, $expectedDate);

        // ============ 単勝オッズ・人気を補完 ============
        // 2019年以降、race.netkeiba.com の出馬表 HTML には単勝オッズが直接
        // 埋め込まれておらず、"---.-" のプレースホルダーのみが出力される。
        // 実際の値は jquery.odds_update.js が非同期に叩く
        // /api/api_get_jra_odds.html (JRA単複オッズAPI) から取得して
        // JavaScript で描画される仕様のため、静的HTML解析だけでは
        // win_odds / popularity が常に null になってしまう。
        // ここで同じAPIを直接叩き、馬番をキーに出馬表データへマージする。
        try {
            $oddsMap = $this->fetchWinOdds($raceId);
            if (!empty($oddsMap) && !empty($data['results'])) {
                foreach ($data['results'] as &$row) {
                    $hno = (int) ($row['horse_number'] ?? 0);
                    if ($hno < 1 || !isset($oddsMap[$hno])) continue;
                    if (isset($oddsMap[$hno]['win_odds'])) {
                        $row['win_odds'] = $oddsMap[$hno]['win_odds'];
                    }
                    if (isset($oddsMap[$hno]['popularity'])) {
                        $row['popularity'] = $oddsMap[$hno]['popularity'];
                    }
                }
                unset($row);
            }
        } catch (\Throwable $e) {
            Log::warning("Netkeiba: fetchWinOdds failed for race_id={$raceId}: " . $e->getMessage());
        }

        return $data;
    }

    /**
     * 単勝オッズ・人気を JRA公式オッズAPI から取得
     *
     * URL: https://race.netkeiba.com/api/api_get_jra_odds.html
     *   ?pid=api_get_jra_odds&race_id={id}&type=1&action=init&sort=odds&compress=0
     *
     * type=1 は単勝・複勝セット。レスポンスの odds.1 が単勝、odds.2 が複勝。
     * 各エントリは [オッズ, (複勝の場合は上限オッズ), 人気順] の配列。
     * 馬番はゼロ埋め2桁文字列 ("01", "02", ...) がキーになる。
     *
     * status:
     *   - "result" : 確定オッズ (レース発走後など)
     *   - "middle" : 発売中の中間オッズ
     *   - "yoso"   : 予想オッズ(オッズ非公開時間帯)。数値はダミーなので使わない
     *   - "NG"     : 取得失敗
     *
     * @param string $raceId
     * @return array<int, array{win_odds?: float, popularity?: int}>  horse_number => [...]
     */
    public function fetchWinOdds(string $raceId): array
    {
        $this->respectInterval();

        Log::info("Netkeiba fetch win odds (api): race_id={$raceId}");

        $response = $this->httpRace->get('/api/api_get_jra_odds.html', [
            'query' => [
                'pid'      => 'api_get_jra_odds',
                'input'    => 'UTF-8',
                'output'   => 'json',
                'race_id'  => $raceId,
                'type'     => '1',
                'action'   => 'init',
                'sort'     => 'odds',
                'compress' => '0',
            ],
            'headers' => [
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer'          => "https://race.netkeiba.com/race/shutuba.html?race_id={$raceId}",
                'Accept'           => 'application/json, text/javascript, */*; q=0.01',
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("netkeiba odds api HTTP {$status} for race_id={$raceId}");
        }

        $body = $response->getBody()->getContents();
        $json = json_decode($body, true);
        if (!is_array($json)) {
            Log::warning("Netkeiba: odds api invalid json for race_id={$raceId}");
            return [];
        }

        // "yoso"(予想オッズ) はダミー値のため採用しない。
        // "NG" は取得失敗。"result"/"middle" のみ実数値として扱う。
        $oddsStatus = $json['status'] ?? '';
        if (!in_array($oddsStatus, ['result', 'middle'], true)) {
            return [];
        }

        $tanOdds = $json['data']['odds']['1'] ?? null;
        if (!is_array($tanOdds) || empty($tanOdds)) {
            return [];
        }

        $result = [];
        foreach ($tanOdds as $umaban => $row) {
            $hno = (int) $umaban;
            if ($hno < 1 || !is_array($row) || !isset($row[0])) continue;

            $entry = [];
            if (is_numeric($row[0])) {
                $entry['win_odds'] = (float) $row[0];
            }
            // 3番目の要素が人気順 (0番目=単勝オッズ, 1番目=複勝上限オッズ, 2番目=人気)
            if (isset($row[2]) && is_numeric($row[2])) {
                $entry['popularity'] = (int) $row[2];
            }
            if (!empty($entry)) {
                $result[$hno] = $entry;
            }
        }

        return $result;
    }

    /**
     * 出馬表 HTML をパース
     *
     * URL: https://race.netkeiba.com/race/shutuba.html?race_id={id}
     * DOM:
     *   - h1.RaceName               : レース名
     *   - .RaceData01               : "12:10発走 / 芝1600m / 天候:曇 / 馬場:稍"  ※発走前は天候/馬場が無いことも多い
     *   - .RaceData02               : "4回 中山 3日目 サラ系2歳 新馬 ..."
     *   - table.Shutuba_Table       : 出馬表テーブル (tr.HorseList)
     *
     * 列構成 (出馬表):
     *   枠 / 馬番 / 馬名(性齢の隣に印列を含む場合あり) / 性齢 / 斤量 / 騎手 / 厩舎 / 馬体重(増減) / 単勝オッズ / 人気
     *
     * 出馬表ページは結果ページと列構成が異なるため、ヘッダ行から列インデックスを動的に判定する。
     */
    protected function parseShutubaHtml(string $raceId, string $html, ?string $expectedDate = null): array
    {
        $html = preg_replace('/<!--.*?-->/su', '', $html);
        $crawler = new Crawler($html);

        $data = ['netkeiba_id' => $raceId];

        // race_id 構造分解
        $year = substr($raceId, 0, 4);
        $data['venue_code']  = substr($raceId, 4, 2);
        $data['kaisai_kai']  = (int) substr($raceId, 6, 2);
        $data['kaisai_day']  = (int) substr($raceId, 8, 2);
        $data['race_number'] = (int) substr($raceId, 10, 2);

        // ============ レース名 ============
        $name = $this->extractText($crawler, 'h1.RaceName, .RaceName');
        if ($name) {
            $name = preg_replace('/\s+/u', ' ', $name);
            $data['name'] = trim($name);
        } else {
            $data['name'] = "Race {$raceId}";
        }

        // ============ RaceData01 から距離・トラック・天候・馬場 ============
        $data01 = '';
        try {
            $data01 = $this->cleanText($crawler->filter('.RaceData01')->text(''));
        } catch (\Throwable $e) {}

        if (preg_match('/(芝|ダート|ダ|障害|障)\s*(?:\(?(右|左|直線)\)?)?\s*(\d+)\s*m/u', $data01, $m)) {
            $data['track_type'] = match ($m[1]) {
                '芝'             => '芝',
                'ダ', 'ダート'   => 'ダート',
                '障', '障害'     => '障害',
                default          => $m[1],
            };
            $data['direction'] = $m[2] ?: null;
            $data['distance']  = (int) $m[3];
        }
        if (preg_match('/天候\s*[:：]?\s*(晴|曇|小雨|雨|小雪|雪)/u', $data01, $m)) {
            $data['weather'] = $m[1];
        }
        if (preg_match('/馬場\s*[:：]?\s*(稍重|不良|良|稍|重|不)/u', $data01, $m)) {
            $cond = $m[1];
            $data['course_condition'] = match ($cond) {
                '稍'    => '稍重',
                '不'    => '不良',
                default => $cond,
            };
        }

        // ============ 開催日 ============
        // expectedDate（呼び出し側が知っている本当の開催日）を最優先で採用し、
        // それが無ければ狭い DOM ノードから race_id 年と一致するものだけを抽出する。
        $rd = $this->extractRaceDateFromCrawler($crawler, $raceId, $expectedDate);
        if ($rd !== null) {
            $data['race_date'] = $rd;
        }

        // ============ 発走時刻 (Phase EV-3) ============
        // .RaceData01 に含まれる "12:10発走" パターンを抽出し、
        // 開催日 + "HH:MM" を DATETIME (YYYY-MM-DD HH:MM:00) で保存。
        // race_date が確定していない場合は post_time もスキップ (整合性優先)。
        if (!empty($data['race_date']) && preg_match('/(\d{1,2})\s*[:：]\s*(\d{2})\s*発走/u', $data01, $mm)) {
            $hh = (int) $mm[1];
            $mi = (int) $mm[2];
            if ($hh >= 0 && $hh <= 23 && $mi >= 0 && $mi <= 59) {
                $data['post_time'] = sprintf('%s %02d:%02d:00', $data['race_date'], $hh, $mi);
            }
        }

        // ============ 出馬表テーブル ============
        $data['results']      = $this->parseShutubaTable($crawler);
        $data['horses_count'] = count($data['results']);

        // 出馬表段階では払戻・ラップは存在しない
        $data['payouts']   = [];
        $data['is_shutuba'] = true;

        return $data;
    }

    /**
     * table.Shutuba_Table を行ごとにパースして出走馬配列に変換
     *
     * ヘッダ行から列名→列インデックスのマップを構築する（列構成変更にロバスト）。
     * 出馬表は結果と異なり「着順 / タイム / 着差 / 通過 / 上り」の列が無い。
     *
     * @return array[]
     */
    protected function parseShutubaTable(Crawler $crawler): array
    {
        $results = [];

        $table = null;
        try {
            $cand = $crawler->filter('table.Shutuba_Table');
            if ($cand->count() > 0) $table = $cand->first();
        } catch (\Throwable $e) {}

        if (!$table) {
            // フォールバック: id でも試す
            try {
                $cand = $crawler->filter('table#Shutuba_Table, table.RaceTable01.ShutubaTable');
                if ($cand->count() > 0) $table = $cand->first();
            } catch (\Throwable $e) {}
        }

        if (!$table) {
            Log::warning("Netkeiba: Shutuba_Table not found");
            return [];
        }

        // ヘッダ列マップ構築（複数 thead/tr があるので最後の th 行を使う）
        $headerMap = [];
        try {
            $headerRows = $table->filter('tr');
            $headerCells = null;
            $headerRows->each(function (Crawler $tr) use (&$headerCells) {
                $ths = $tr->filter('th');
                if ($ths->count() >= 5) {
                    $headerCells = $ths;
                }
            });
            if ($headerCells) {
                $headerCells->each(function (Crawler $th, $i) use (&$headerMap) {
                    $label = $this->cleanText($th->text(''));
                    $key = $this->normalizeShutubaHeader($label);
                    if ($key) $headerMap[$i] = $key;
                });
            }
        } catch (\Throwable $e) {}

        // データ行 (tr.HorseList)
        try {
            $rows = $table->filter('tr.HorseList');
        } catch (\Throwable $e) {
            return [];
        }
        if ($rows->count() === 0) {
            Log::warning("Netkeiba: tr.HorseList not found in Shutuba_Table");
            return [];
        }

        $rows->each(function (Crawler $tr) use (&$results, $headerMap) {
            $tds = $tr->filter('td');
            if ($tds->count() < 5) return;

            $row = [];
            $tds->each(function (Crawler $td, $colIdx) use (&$row, $headerMap) {
                // ヘッダから判明したキーを優先
                $key = $headerMap[$colIdx] ?? null;
                $cls = $td->attr('class') ?? '';

                // class ベースの追加判定（netkeiba の出馬表は class が手堅い）
                if (!$key) {
                    if (str_contains($cls, 'Waku')) $key = 'frame_number';
                    elseif (str_contains($cls, 'Umaban')) $key = 'horse_number';
                    elseif (str_contains($cls, 'HorseInfo')) $key = 'horse_name';
                    elseif (str_contains($cls, 'Barei')) $key = 'sex_age';
                    elseif (str_contains($cls, 'Jockey')) $key = 'jockey_name';
                    elseif (str_contains($cls, 'Trainer')) $key = 'trainer_name';
                    elseif (str_contains($cls, 'Weight')) $key = 'horse_weight_str';
                    elseif (str_contains($cls, 'Popular')) $key = 'win_odds';
                    elseif (str_contains($cls, 'Odds')) $key = 'win_odds';
                }
                if (!$key) return;

                // リンクからは netkeiba_id を回収
                try {
                    $a = $td->filter('a')->first();
                    if ($a->count() > 0) {
                        $val = $this->cleanText($a->text(''));
                        if ($val !== '') {
                            $row[$key] = $val;
                            $href = $a->attr('href') ?? '';
                            if ($key === 'horse_name' && preg_match('#/horse/(\d+)#', $href, $m)) {
                                $row['horse_netkeiba_id'] = $m[1];
                            } elseif ($key === 'jockey_name' && preg_match('#/jockey/(?:result/(?:recent/)?)?(\d+)#', $href, $m)) {
                                $row['jockey_netkeiba_id'] = $m[1];
                            } elseif ($key === 'trainer_name' && preg_match('#/trainer/(?:result/(?:recent/)?)?(\d+)#', $href, $m)) {
                                $row['trainer_netkeiba_id'] = $m[1];
                            }
                            return;
                        }
                    }
                } catch (\Throwable $e) {}

                $row[$key] = $this->cleanText($td->text(''));
            });

            if (empty($row)) return;

            // 出馬表専用の正規化（normalizeRow を流用しつつ、結果系フィールドは null のまま）
            $normalized = $this->normalizeRow($row);

            // 出馬表段階で確実に存在しないフィールドを null で埋める
            $normalized['finish_position']   = null;
            $normalized['time']              = null;
            $normalized['margin']            = null;
            $normalized['corner_positions']  = null;
            $normalized['last_3f']           = null;

            // 馬番が取れていない行はスキップ（無効行）
            if (empty($normalized['horse_number'])) return;

            $results[] = $normalized;
        });

        // 馬番昇順でソート
        usort($results, fn($a, $b) => ($a['horse_number'] ?? 0) <=> ($b['horse_number'] ?? 0));

        return $results;
    }

    /**
     * 出馬表ヘッダラベルを正規化キーに変換
     *
     * 結果ページとの差分:
     *   - 「印」「お気に入り」等の出馬表特有列は無視
     *   - 「着順」「タイム」「着差」「通過」「上り」は出馬表に存在しない
     */
    protected function normalizeShutubaHeader(string $label): ?string
    {
        $label = trim(preg_replace('/\s+/u', '', $label));
        $map = [
            '枠'      => 'frame_number',
            '枠番'    => 'frame_number',
            '馬番'    => 'horse_number',
            '馬名'    => 'horse_name',
            '性齢'    => 'sex_age',
            '斤量'    => 'weight_carried',
            '騎手'    => 'jockey_name',
            '厩舎'    => 'trainer_name',
            '調教師'  => 'trainer_name',
            '馬体重'  => 'horse_weight_str',
            '馬体重(増減)' => 'horse_weight_str',
            '単勝'    => 'win_odds',
            'オッズ'  => 'win_odds',
            '人気'    => 'popularity',
        ];
        return $map[$label] ?? null;
    }

    /**
     * 指定月の開催日一覧を取得
     *
     * netkeibaのカレンダー（/top/calendar.html?year=YYYY&month=MM）と
     * /race/sum/YYYYMM/ ページから開催日を抽出。
     *
     * @param int $year   西暦
     * @param int $month  1-12
     * @return string[]   ['YYYY-MM-DD', ...]
     */
    public function fetchOpenDatesByMonth(int $year, int $month): array
    {
        $this->respectInterval();

        $ymd = sprintf('%04d%02d', $year, $month);
        // 月別開催日カレンダー
        $url = "/race/sum/{$ymd}/";

        try {
            $response = $this->http->get($url);
            if ($response->getStatusCode() !== 200) {
                Log::warning("Netkeiba calendar HTTP {$response->getStatusCode()} for {$ymd}");
                return $this->fallbackOpenDatesByProbing($year, $month);
            }
            $contentType = $response->getHeaderLine('Content-Type');
            $html = $this->decodeHtml($response->getBody()->getContents(), $contentType);
        } catch (\Throwable $e) {
            Log::warning("Netkeiba calendar fetch error: " . $e->getMessage());
            return $this->fallbackOpenDatesByProbing($year, $month);
        }

        $crawler = new Crawler($html);
        $dates = [];
        // 開催日リンク: /race/list/YYYYMMDD/
        $crawler->filter('a[href*="/race/list/"]')->each(function ($node) use (&$dates) {
            $href = $node->attr('href');
            if ($href && preg_match('|/race/list/(\d{8})/?|', $href, $m)) {
                $d = $m[1];
                $dates[sprintf('%s-%s-%s', substr($d, 0, 4), substr($d, 4, 2), substr($d, 6, 2))] = true;
            }
        });

        $list = array_keys($dates);
        sort($list);

        // ページに開催情報が無い場合（古いページや形式変更時）は曜日ベースのフォールバック
        if (empty($list)) {
            return $this->fallbackOpenDatesByProbing($year, $month);
        }

        return $list;
    }

    /**
     * カレンダーが取れなかった場合のフォールバック
     * 月の土日＋祝月（≒月曜）を候補日として返す。実際にレースが無ければ後段でスキップされる。
     */
    protected function fallbackOpenDatesByProbing(int $year, int $month): array
    {
        $dates = [];
        $first = mktime(0, 0, 0, $month, 1, $year);
        $daysInMonth = (int) date('t', $first);
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $ts = mktime(0, 0, 0, $month, $d, $year);
            $w = (int) date('w', $ts); // 0=日,6=土
            // 土日のみ（保守的）
            if ($w === 0 || $w === 6) {
                $dates[] = date('Y-m-d', $ts);
            }
        }
        return $dates;
    }

    /**
     * 開催日からレースID一覧を取得
     *
     * 取得先:
     *   1) https://db.netkeiba.com/race/list/{YYYYMMDD}/                        (旧DB)
     *   2) https://race.netkeiba.com/top/race_list_sub.html?kaisai_date={YMD}   (新DB)
     * 1) で 0件しか取れない場合に 2) にフォールバックする。
     * 2025年などの最近の日付は 1) が 403 を返すため、実質 2) が主経路となる。
     */
    public function fetchRaceIdsByDate(string $date): array
    {
        $ymd = str_replace('-', '', $date);

        // ---------- 1) db.netkeiba.com を試す ----------
        $this->respectInterval();
        $ids = [];
        try {
            $response = $this->http->get("/race/list/{$ymd}/");
            $status = $response->getStatusCode();
            if ($status === 200) {
                $contentType = $response->getHeaderLine('Content-Type');
                $html = $this->decodeHtml($response->getBody()->getContents(), $contentType);
                $crawler = new Crawler($html);
                $crawler->filter('a[href*="/race/"]')->each(function ($node) use (&$ids) {
                    $href = $node->attr('href');
                    if ($href && preg_match('|/race/(\d{12})/?|', $href, $m)) {
                        $ids[$m[1]] = true;
                    }
                });
            } else {
                Log::info("Netkeiba race list (db) HTTP {$status} for {$ymd}, will try race.netkeiba.com");
            }
        } catch (\Throwable $e) {
            Log::warning("Netkeiba race list (db) error for {$ymd}: " . $e->getMessage());
        }

        if (!empty($ids)) {
            return array_keys($ids);
        }

        // ---------- 2) race.netkeiba.com にフォールバック ----------
        $this->respectInterval();
        try {
            $response = $this->httpRace->get("/top/race_list_sub.html?kaisai_date={$ymd}");
            $status = $response->getStatusCode();
            if ($status !== 200) {
                Log::warning("Netkeiba race list (race) HTTP {$status} for {$ymd}");
                return [];
            }
            $contentType = $response->getHeaderLine('Content-Type');
            $html = $this->decodeHtml($response->getBody()->getContents(), $contentType);

            // race.netkeiba.com は href に "race_id=YYYYMMDDXXXX" の形で出てくる
            if (preg_match_all('/race_id=(\d{12})/', $html, $matches)) {
                foreach ($matches[1] as $rid) {
                    $ids[$rid] = true;
                }
            }
            // 念のため /race/{12桁}/ パターンも回収
            if (preg_match_all('|/race/(\d{12})/?|', $html, $matches)) {
                foreach ($matches[1] as $rid) {
                    $ids[$rid] = true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Netkeiba race list (race) error for {$ymd}: " . $e->getMessage());
        }

        return array_keys($ids);
    }

    /**
     * HTMLのエンコーディングを正しく判定してUTF-8に変換
     *
     * netkeiba.com は db.netkeiba.com 配下が長らく EUC-JP で配信されている。
     * 判定優先順位:
     *   1) HTTP レスポンスヘッダの Content-Type: charset=...
     *   2) HTML 本文の <meta charset=...> / <meta http-equiv ... charset=...>
     *   3) mb_detect_encoding (strict)。ただし EUC-JP/SJIS/UTF-8 混同を避けるため
     *      バイトパターンで EUC-JP らしさを別途判定
     *   4) 最終フォールバック: EUC-JP を仮定（netkeiba旧ページの実情に合わせる）
     */
    protected function decodeHtml(string $body, string $contentType = ''): string
    {
        // BOM除去
        if (substr($body, 0, 3) === "\xEF\xBB\xBF") {
            $body = substr($body, 3);
            return $body; // BOM付きはほぼ確実にUTF-8
        }

        $declared = null;

        // 1) HTTPヘッダ Content-Type: text/html; charset=XXX
        if ($contentType && preg_match('/charset\s*=\s*["\']?([\w-]+)/i', $contentType, $m)) {
            $declared = strtolower($m[1]);
        }

        // 2) HTML 本文の meta charset
        if (!$declared) {
            // <meta charset="..."> または <meta http-equiv="Content-Type" content="text/html; charset=...">
            if (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w-]+)/i', substr($body, 0, 4096), $m)) {
                $declared = strtolower($m[1]);
            }
        }

        if ($declared) {
            $declared = $this->canonicalizeCharset($declared);
            if (in_array($declared, ['UTF-8'], true)) {
                return $body;
            }
            $converted = @mb_convert_encoding($body, 'UTF-8', $declared);
            if ($converted !== false && $converted !== '') {
                return $this->stripCharsetMeta($converted);
            }
        }

        // 3) バイトパターンで EUC-JP らしさを判定
        //    EUC-JP の漢字は 0xA1-0xFE 0xA1-0xFE のペア。UTF-8 では 0xE0-0xEF で始まる3バイトが多い。
        //    まず純粋な UTF-8 として valid か確認
        $isValidUtf8 = mb_check_encoding($body, 'UTF-8');
        $eucScore = 0;
        $utfScore = 0;
        $len = strlen($body);
        $sampleLen = min($len, 8000);
        for ($i = 0; $i < $sampleLen - 1; $i++) {
            $b1 = ord($body[$i]);
            if ($b1 >= 0xA1 && $b1 <= 0xFE) {
                $b2 = ord($body[$i + 1]);
                if ($b2 >= 0xA1 && $b2 <= 0xFE) {
                    $eucScore++;
                    $i++;
                    continue;
                }
            }
            if ($b1 >= 0xE0 && $b1 <= 0xEF && $i + 2 < $sampleLen) {
                $b2 = ord($body[$i + 1]);
                $b3 = ord($body[$i + 2]);
                if ($b2 >= 0x80 && $b2 <= 0xBF && $b3 >= 0x80 && $b3 <= 0xBF) {
                    $utfScore++;
                    $i += 2;
                    continue;
                }
            }
        }

        // EUC-JP のスコアが優勢なら EUC-JP として扱う
        if ($eucScore > $utfScore * 2 && $eucScore > 20) {
            $converted = @mb_convert_encoding($body, 'UTF-8', 'EUC-JP');
            if ($converted !== false && $converted !== '') {
                return $this->stripCharsetMeta($converted);
            }
        }

        // 4) UTF-8 として valid なら UTF-8 として扱う
        if ($isValidUtf8 && $utfScore > 0) {
            return $body;
        }

        // 5) mb_detect_encoding（strict）
        $detected = mb_detect_encoding($body, ['UTF-8', 'EUC-JP', 'SJIS-win', 'SJIS', 'JIS'], true);
        if ($detected && $detected !== 'UTF-8') {
            $converted = @mb_convert_encoding($body, 'UTF-8', $detected);
            if ($converted !== false && $converted !== '') {
                return $this->stripCharsetMeta($converted);
            }
        }

        // 6) 最終フォールバック: netkeiba 旧ページに合わせて EUC-JP を仮定
        $converted = @mb_convert_encoding($body, 'UTF-8', 'EUC-JP');
        if ($converted !== false && $converted !== '') {
            return $this->stripCharsetMeta($converted);
        }

        return $body;
    }

    /**
     * charset エイリアスを正規化
     */
    protected function canonicalizeCharset(string $cs): string
    {
        $cs = strtoupper(str_replace('_', '-', $cs));
        return match ($cs) {
            'UTF8', 'UTF-8'         => 'UTF-8',
            'EUCJP', 'EUC-JP', 'X-EUC-JP' => 'EUC-JP',
            'SJIS', 'SHIFT-JIS', 'SHIFT_JIS', 'MS_KANJI', 'CP932' => 'SJIS-win',
            'ISO-2022-JP', 'JIS'    => 'JIS',
            default                 => $cs,
        };
    }

    /**
     * 変換後HTMLに残る古い charset 宣言を除去（DOMパーサが二度変換しないように）
     */
    protected function stripCharsetMeta(string $html): string
    {
        return preg_replace(
            '/<meta[^>]+charset\s*=\s*["\']?[\w-]+["\']?[^>]*>/i',
            '<meta charset="UTF-8">',
            $html,
            1
        ) ?? $html;
    }

    /**
     * リクエスト間隔を空ける
     */
    protected function respectInterval(): void
    {
        $interval = $this->intervalOverride !== null
            ? $this->intervalOverride
            : (int) config('services.netkeiba.request_interval', 5);

        if ($interval <= 0) {
            Cache::put('netkeiba_last_request', time(), now()->addMinutes(10));
            return;
        }

        $last = Cache::get('netkeiba_last_request', 0);
        $diff = time() - $last;
        if ($diff < $interval) {
            sleep($interval - $diff);
        }
        Cache::put('netkeiba_last_request', time(), now()->addMinutes(10));
    }

    /**
     * netkeibaのHTMLをパースして構造化データに変換
     */
    protected function parseRaceHtml(string $raceId, string $html, ?string $expectedDate = null): array
    {
        // パース前に HTML コメントを除去（<!--img ...--> がレース名等に紛れ込むのを防止）
        $html = preg_replace('/<!--.*?-->/su', '', $html);
        // 不正に閉じてないコメント残骸も削る
        $html = preg_replace('/<!--[^>]*$/s', '', $html);

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

        // ============ レース名 ============
        // db.netkeiba.com の現行構造: <dl class="racedata fc"><dd><h1>レース名</h1>...
        $name = $this->extractText($crawler, 'dl.racedata h1, .data_intro h1, .RaceName, .race_title h1');
        if (!$name) {
            try { $name = $this->cleanText($crawler->filter('h1')->first()->text('')); } catch (\Throwable $e) {}
        }
        // レース名にHTMLタグ・"!--" 等の残骸が残っていれば再度クレンジング
        if ($name) {
            $name = preg_replace('/!--.*$/u', '', $name);
            $name = trim(preg_replace('/\s+/u', ' ', $name));
        }
        $data['name'] = $name ?: "Race {$raceId}";

        // ============ 開催日 ============
        // 狭い DOM ノードから race_id 年と一致する日付のみ採用する
        $rd = $this->extractRaceDateFromCrawler($crawler, $raceId, $expectedDate);
        if ($rd !== null) {
            $data['race_date'] = $rd;
        }

        // ============ レース詳細（距離・トラック・馬場・天候） ============
        $diaryText = '';
        try {
            $diaryText = $this->cleanText($crawler->filter('.data_intro')->text(''));
        } catch (\Throwable $e) {
            try {
                $diaryText = $this->cleanText($crawler->filter('body')->text(''));
            } catch (\Throwable $e2) {
                $diaryText = '';
            }
        }

        // トラック+方向+距離: 「芝右1600m」「ダ左1200m」「障2910m」
        if (preg_match('/(芝|ダート|ダ|障害|障)\s*(右|左|直線)?\s*(\d+)\s*m/u', $diaryText, $m)) {
            $data['track_type'] = match($m[1]) {
                '芝' => '芝',
                'ダ', 'ダート' => 'ダート',
                '障', '障害' => '障害',
                default => $m[1],
            };
            $data['direction'] = $m[2] ?: null;
            $data['distance'] = (int) $m[3];
        }
        // 天候: 「天候:晴」「天候 : 曇」「天候/晴」「天候 晴」など。
        // 値の直後が空白/全角空白/スラッシュ/行末のいずれでもマッチ。
        if (preg_match('/天候\s*[:：\/]?\s*(晴|曇|小雨|雨|小雪|雪)/u', $diaryText, $m)) {
            $data['weather'] = $m[1];
        }
        // 馬場(コンディション): 良/稍重/重/不良。
        // 「馬場:良」「芝 : 稍重」「ダ : 重」「馬場/良」など。
        if (preg_match('/(?:馬場|芝|ダート|ダ)\s*[:：\/]?\s*(稍重|不良|良|重)/u', $diaryText, $m)) {
            $data['course_condition'] = $m[1];
        }

        // ============ 結果テーブル ============
        // 現行netkeiba: <table class="race_table_01 nk_tb_common">
        // ヘッダ列の意味で取得（列インデックスではなく見出しベース）
        $results = $this->parseResultsTable($crawler);

        $data['results'] = $results;
        $data['horses_count'] = count($results);

        // ============ ラップタイム / 前後半3F ============
        $lap = $this->extractLapTimes($crawler, $html);
        if ($lap) {
            if (!empty($lap['lap_times'])) $data['lap_times'] = $lap['lap_times'];
            if (!empty($lap['first_3f']))  $data['first_3f']  = $lap['first_3f'];
            if (!empty($lap['last_3f']))   $data['last_3f']   = $lap['last_3f'];
        }

        // ============ 払戻データ ============
        $data['payouts'] = $this->parsePayoutTables($crawler);

        return $data;
    }

    /**
     * race.netkeiba.com (新DB) のレース結果ページをパース
     *
     * URL: https://race.netkeiba.com/race/result.html?race_id={id}
     * DOM:
     *   - h1.RaceName               : レース名
     *   - .RaceData01               : "12:10発走 / 芝1600m / 天候:曇 / 馬場:稍"
     *   - .RaceData02               : "4回 中山 3日目 サラ系2歳 新馬 ..."
     *   - table#All_Result_Table    : 結果テーブル (tr.HorseList)
     *   - table.Payout_Detail_Table : 払戻テーブル
     */
    protected function parseRaceResultHtml(string $raceId, string $html, ?string $expectedDate = null): array
    {
        $html = preg_replace('/<!--.*?-->/su', '', $html);
        $crawler = new Crawler($html);

        $data = ['netkeiba_id' => $raceId];

        // race_id 構造分解
        $year = substr($raceId, 0, 4);
        $data['venue_code'] = substr($raceId, 4, 2);
        $data['kaisai_kai'] = (int) substr($raceId, 6, 2);
        $data['kaisai_day'] = (int) substr($raceId, 8, 2);
        $data['race_number'] = (int) substr($raceId, 10, 2);

        // ============ レース名 ============
        $name = $this->extractText($crawler, 'h1.RaceName, .RaceName');
        if ($name) {
            $name = preg_replace('/\s+/u', ' ', $name);
            $data['name'] = trim($name);
        } else {
            $data['name'] = "Race {$raceId}";
        }

        // ============ RaceData01 から距離・トラック・天候・馬場 ============
        $data01 = '';
        try {
            $data01 = $this->cleanText($crawler->filter('.RaceData01')->text(''));
        } catch (\Throwable $e) {}

        // 「芝1600m (右 外 B)」「ダ1200m」「障2910m」
        if (preg_match('/(芝|ダート|ダ|障害|障)\s*(?:\(?(右|左|直線)\)?)?\s*(\d+)\s*m/u', $data01, $m)) {
            $data['track_type'] = match ($m[1]) {
                '芝'             => '芝',
                'ダ', 'ダート'   => 'ダート',
                '障', '障害'     => '障害',
                default          => $m[1],
            };
            $data['direction'] = $m[2] ?: null;
            $data['distance']  = (int) $m[3];
        }
        // 「天候:曇」「天候 曇」
        if (preg_match('/天候\s*[:：]?\s*(晴|曇|小雨|雨|小雪|雪)/u', $data01, $m)) {
            $data['weather'] = $m[1];
        }
        // 「馬場:稍」(良/稍/重/不) — 1文字略記の場合あり
        if (preg_match('/馬場\s*[:：]?\s*(稍重|不良|良|稍|重|不)/u', $data01, $m)) {
            $cond = $m[1];
            $data['course_condition'] = match ($cond) {
                '稍'    => '稍重',
                '不'    => '不良',
                default => $cond,
            };
        }

        // ============ RaceData02 から開催日・場名 ============
        // 狭い DOM ノードから race_id 年と一致する日付のみ採用する
        $rd = $this->extractRaceDateFromCrawler($crawler, $raceId, $expectedDate);
        if ($rd !== null) {
            $data['race_date'] = $rd;
        }

        // ============ 発走時刻 (Phase EV-3) ============
        // .RaceData01 に含まれる "12:10発走" パターンを抽出し、
        // 開催日 + "HH:MM" を DATETIME (YYYY-MM-DD HH:MM:00) で保存。
        if (!empty($data['race_date']) && preg_match('/(\d{1,2})\s*[:：]\s*(\d{2})\s*発走/u', $data01, $mm)) {
            $hh = (int) $mm[1];
            $mi = (int) $mm[2];
            if ($hh >= 0 && $hh <= 23 && $mi >= 0 && $mi <= 59) {
                $data['post_time'] = sprintf('%s %02d:%02d:00', $data['race_date'], $hh, $mi);
            }
        }

        // ============ 結果テーブル ============
        $data['results'] = $this->parseResultTableNew($crawler);
        $data['horses_count'] = count($data['results']);

        // ============ ラップタイム / 前後半3F ============
        $lap = $this->extractLapTimes($crawler, $html);
        if ($lap) {
            if (!empty($lap['lap_times'])) $data['lap_times'] = $lap['lap_times'];
            if (!empty($lap['first_3f']))  $data['first_3f']  = $lap['first_3f'];
            if (!empty($lap['last_3f']))   $data['last_3f']   = $lap['last_3f'];
        }

        // ============ 払戻テーブル ============
        $data['payouts'] = $this->parsePayoutTablesNew($crawler);

        return $data;
    }

    /**
     * race.netkeiba.com の結果テーブル (table#All_Result_Table > tr.HorseList) をパース
     *
     * 列構成 (header):
     *   着 / 枠 / 馬番 / 馬名 / 性齢 / 斤量 / 騎手 / タイム / 着差 / 人気 / 単勝オッズ / 後3F / 通過順 / 厩舎 / 馬体重(増減)
     */
    protected function parseResultTableNew(Crawler $crawler): array
    {
        $results = [];
        $rows = null;
        try {
            $rows = $crawler->filter('table#All_Result_Table tr.HorseList');
            if ($rows->count() === 0) {
                $rows = $crawler->filter('table.RaceTable01 tr.HorseList');
            }
        } catch (\Throwable $e) {}

        if (!$rows || $rows->count() === 0) {
            Log::warning("Netkeiba(new): result table not found");
            return [];
        }

        $rows->each(function (Crawler $tr) use (&$results) {
            $tds = $tr->filter('td');
            if ($tds->count() < 10) return;

            $row = [];

            // 着順 (td[0])
            try { $row['finish_position'] = $this->cleanText($tds->eq(0)->text('')); } catch (\Throwable $e) {}

            // 枠番 (td[1])
            try { $row['frame_number'] = $this->parseInt($this->cleanText($tds->eq(1)->text(''))); } catch (\Throwable $e) {}

            // 馬番 (td[2])
            try { $row['horse_number'] = $this->parseInt($this->cleanText($tds->eq(2)->text(''))); } catch (\Throwable $e) {}

            // 馬名 + horse_netkeiba_id (td[3])
            try {
                $a = $tds->eq(3)->filter('a')->first();
                if ($a->count() > 0) {
                    $row['horse_name'] = $this->cleanText($a->text(''));
                    $href = $a->attr('href') ?: '';
                    if (preg_match('#/horse/(\d+)#', $href, $m)) {
                        $row['horse_netkeiba_id'] = $m[1];
                    }
                } else {
                    $row['horse_name'] = $this->cleanText($tds->eq(3)->text(''));
                }
            } catch (\Throwable $e) {}

            // 性齢 (td[4])
            try {
                $sa = $this->cleanText($tds->eq(4)->text(''));
                if (preg_match('/(牡|牝|セ)\s*(\d+)/u', $sa, $m)) {
                    $row['sex'] = $m[1];
                    $row['age'] = (int) $m[2];
                }
            } catch (\Throwable $e) {}

            // 斤量 (td[5])
            try {
                $w = $this->cleanText($tds->eq(5)->text(''));
                if (is_numeric($w)) $row['weight_carried'] = (float) $w;
            } catch (\Throwable $e) {}

            // 騎手 (td[6]) + jockey_netkeiba_id
            try {
                $a = $tds->eq(6)->filter('a')->first();
                if ($a->count() > 0) {
                    $row['jockey_name'] = $this->cleanText($a->text(''));
                    $href = $a->attr('href') ?: '';
                    if (preg_match('#/jockey/(?:result/(?:recent/)?)?(\d+)#', $href, $m)) {
                        $row['jockey_netkeiba_id'] = $m[1];
                    }
                } else {
                    $row['jockey_name'] = $this->cleanText($tds->eq(6)->text(''));
                }
            } catch (\Throwable $e) {}

            // タイム (td[7])
            try {
                $t = $this->cleanText($tds->eq(7)->text(''));
                if ($t !== '') $row['time'] = mb_substr($t, 0, 10);
            } catch (\Throwable $e) {}

            // 着差 (td[8])
            try {
                $m = $this->cleanText($tds->eq(8)->text(''));
                if ($m !== '') $row['margin'] = mb_substr($m, 0, 10);
            } catch (\Throwable $e) {}

            // 人気 (td[9])
            try {
                $p = $this->cleanText($tds->eq(9)->text(''));
                if (is_numeric($p)) $row['popularity'] = (int) $p;
            } catch (\Throwable $e) {}

            // 単勝オッズ (td[10])
            if ($tds->count() > 10) {
                try {
                    $o = $this->cleanText($tds->eq(10)->text(''));
                    if (is_numeric($o)) $row['win_odds'] = (float) $o;
                } catch (\Throwable $e) {}
            }

            // 上り3F (td[11])
            if ($tds->count() > 11) {
                try {
                    $l = $this->cleanText($tds->eq(11)->text(''));
                    if ($l !== '') $row['last_3f'] = mb_substr($l, 0, 6);
                } catch (\Throwable $e) {}
            }

            // 通過順 (td[12])
            if ($tds->count() > 12) {
                try {
                    $cp = $this->cleanText($tds->eq(12)->text(''));
                    if ($cp !== '') $row['corner_positions'] = mb_substr($cp, 0, 30);
                } catch (\Throwable $e) {}
            }

            // 厩舎 (td[13]) + trainer_netkeiba_id
            if ($tds->count() > 13) {
                try {
                    $a = $tds->eq(13)->filter('a')->first();
                    if ($a->count() > 0) {
                        $row['trainer_name'] = $this->cleanText($a->text(''));
                        $href = $a->attr('href') ?: '';
                        if (preg_match('#/trainer/(?:result/(?:recent/)?)?(\d+)#', $href, $m)) {
                            $row['trainer_netkeiba_id'] = $m[1];
                        }
                    } else {
                        $row['trainer_name'] = $this->cleanText($tds->eq(13)->text(''));
                    }
                } catch (\Throwable $e) {}
            }

            // 馬体重(増減) (td[14])  例: "442(0)" / "480(+2)"
            if ($tds->count() > 14) {
                try {
                    $hw = $this->cleanText($tds->eq(14)->text(''));
                    if (preg_match('/(\d+)\s*\(\s*([+\-]?\d+)\s*\)/', $hw, $m)) {
                        $row['horse_weight'] = (int) $m[1];
                        $row['horse_weight_diff'] = (int) $m[2];
                    } elseif (preg_match('/(\d+)/', $hw, $m)) {
                        $row['horse_weight'] = (int) $m[1];
                    }
                } catch (\Throwable $e) {}
            }

            if (!empty($row)) {
                $results[] = $this->normalizeRow($row);
            }
        });

        return $results;
    }

    /**
     * ラップタイム / 前後半3F を抽出
     *
     * netkeiba は2系統あり、両方をカバーする:
     *   db.netkeiba.com   : <table summary="ラップタイム"> または class="race_lap_cell"
     *   race.netkeiba.com : <table class="Race_HaronTime">
     *
     * 抽出対象:
     *   - lap_times[] : 200m毎のラップ秒 (例: [12.5, 11.0, 11.2, ...])
     *   - first_3f    : 前半3F (例: "34.5")
     *   - last_3f     : 後半3F (例: "35.1")
     *
     * netkeiba は通常 "ペース 35.7 - 36.5" の形で前半-後半3F を表示する。
     * lap_times が取れた場合は、前半3F = 先頭3つの合計、後半3F = 末尾3つの合計でも算出可。
     * パース失敗を許容し、可能な範囲で部分的に返す。
     *
     * @return array{lap_times?:array<float>, first_3f?:string, last_3f?:string}|null
     */
    protected function extractLapTimes(Crawler $crawler, string $html): ?array
    {
        $result = [];

        // ============ 1) ラップタイム配列の抽出 ============
        // 候補セレクタ:
        //   - table summary="ラップタイム" (db.netkeiba.com 旧)
        //   - table.race_lap_cell           (db.netkeiba.com)
        //   - table.Race_HaronTime          (race.netkeiba.com)
        $lapText = '';
        $lapSelectors = [
            'table[summary="ラップタイム"]',
            'table.race_lap_cell',
            'table.Race_HaronTime',
            'div.race_lap_cell table',
        ];
        foreach ($lapSelectors as $sel) {
            try {
                $node = $crawler->filter($sel);
                if ($node->count() > 0) {
                    $lapText = $node->first()->text('');
                    if ($lapText !== '') break;
                }
            } catch (\Throwable $e) {
                // 次の候補へ
            }
        }

        // セレクタで取れなかった場合、HTMLから「ラップタイム」「ペース」の周辺テキストを探す
        if ($lapText === '') {
            // 「ラップタイム」または「ペース」を含む table タグを抽出
            if (preg_match('/<table[^>]*>(?:(?!<table).)*?(?:ラップタイム|ペース).*?<\/table>/su', $html, $m)) {
                // tag を除去してテキスト化
                $stripped = preg_replace('/<[^>]+>/', ' ', $m[0]);
                $lapText = $stripped !== null ? $stripped : '';
            }
        }

        if ($lapText !== '') {
            // 「12.5 - 11.0 - 11.2 - 11.5 - ...」形式の数値列を抽出
            // ハイフン/全角ハイフン/ダッシュ系を許容、空白を含めて分解
            $normalized = preg_replace('/[\s\x{3000}\x{00A0}]+/u', ' ', $lapText);
            $normalized = str_replace(['ー', '−', '–', '—', '－'], '-', $normalized);

            // 全数値抽出 (連続したラップ列を取りたい)
            if (preg_match_all('/\d{1,2}\.\d/', $normalized, $matches)) {
                $allNumbers = array_map('floatval', $matches[0]);

                // ペース表記 (前半3F-後半3F = 例 34.5 - 35.1) は通常 ラップ列の後に来る
                // 200m ラップは通常 9.0〜13.5 秒程度、3Fは 32〜40 秒程度なので分離可能
                $lapTimes = [];
                $threeFs  = [];
                foreach ($allNumbers as $n) {
                    if ($n >= 8.0 && $n < 16.0) {
                        $lapTimes[] = $n;
                    } elseif ($n >= 30.0 && $n < 50.0) {
                        $threeFs[] = $n;
                    }
                }

                // 妥当なラップ数 (距離/200m ≒ 5〜18個) のみ採用
                if (count($lapTimes) >= 4 && count($lapTimes) <= 20) {
                    $result['lap_times'] = $lapTimes;
                }

                // ペース表記から前後半3F (上位2つを採用)
                if (count($threeFs) >= 2) {
                    $result['first_3f'] = number_format($threeFs[0], 1, '.', '');
                    $result['last_3f']  = number_format($threeFs[1], 1, '.', '');
                }
            }
        }

        // ============ 2) lap_times から前後半3F を補完算出 ============
        // ペース表記が拾えなかった場合、ラップ配列から先頭3F・末尾3F を計算
        if (!empty($result['lap_times']) && count($result['lap_times']) >= 6) {
            if (empty($result['first_3f'])) {
                $first = array_slice($result['lap_times'], 0, 3);
                $result['first_3f'] = number_format(array_sum($first), 1, '.', '');
            }
            if (empty($result['last_3f'])) {
                $last = array_slice($result['lap_times'], -3);
                $result['last_3f'] = number_format(array_sum($last), 1, '.', '');
            }
        }

        return $result ?: null;
    }

    /**
     * race.netkeiba.com の払戻テーブル (table.Payout_Detail_Table) をパース
     */
    protected function parsePayoutTablesNew(Crawler $crawler): array
    {
        $payouts = [];

        try {
            $tables = $crawler->filter('table.Payout_Detail_Table');
        } catch (\Throwable $e) {
            return [];
        }
        if ($tables->count() === 0) return [];

        $tables->each(function (Crawler $table) use (&$payouts) {
            $table->filter('tr')->each(function (Crawler $tr) use (&$payouts) {
                try {
                    $th = $tr->filter('th')->first();
                    if ($th->count() === 0) return;

                    $kindLabel = $this->cleanText($th->text(''));
                    $kind = $this->normalizeKindLabel($kindLabel);
                    if (!$kind) return;

                    $tds = $tr->filter('td');
                    if ($tds->count() < 2) return;

                    $combos   = $this->extractBrLines($tds->eq(0));
                    $amounts  = $tds->count() > 1 ? $this->extractBrLines($tds->eq(1)) : [];
                    $populars = $tds->count() > 2 ? $this->extractBrLines($tds->eq(2)) : [];

                    foreach ($combos as $i => $rawCombo) {
                        $combo = $this->normalizePayoutCombination($rawCombo, $kind);
                        if ($combo === '') continue;

                        $amountRaw = $amounts[$i] ?? null;
                        $amount = $amountRaw !== null
                            ? (int) preg_replace('/[^\d]/', '', $amountRaw)
                            : null;
                        if (!$amount) continue;

                        $popRaw = $populars[$i] ?? null;
                        $pop = ($popRaw !== null && preg_match('/(\d+)/', $popRaw, $mm))
                            ? (int) $mm[1] : null;

                        $payouts[] = [
                            'kind'        => $kind,
                            'combination' => $combo,
                            'amount'      => $amount,
                            'popularity'  => $pop,
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::warning('Netkeiba(new) payout row parse failed: ' . $e->getMessage());
                }
            });
        });

        return $payouts;
    }

    /**
     * 払戻テーブルをパース
     *
     * netkeibaの払戻は通常 dl.pay_block 内の table.pay_table_01 などに格納される。
     * 各行は: <th>券種</th><td>組合せ(<br>区切り複数)</td><td>払戻(<br>区切り複数)</td><td>人気(<br>区切り複数)</td>
     *
     * @return array  [['kind'=>'tan', 'combination'=>'5', 'amount'=>320, 'popularity'=>2], ...]
     */
    protected function parsePayoutTables(Crawler $crawler): array
    {
        $payouts = [];

        // 候補セレクタ（複数バージョン対応）
        $tables = null;
        foreach (['table.pay_table_01', 'dl.pay_block table', 'table.race_payout_table', 'div.payout_block table'] as $sel) {
            try {
                $candidate = $crawler->filter($sel);
                if ($candidate->count() > 0) {
                    $tables = $candidate;
                    break;
                }
            } catch (\Throwable $e) {}
        }
        if (!$tables || $tables->count() === 0) {
            return [];
        }

        $tables->each(function (Crawler $table) use (&$payouts) {
            $table->filter('tr')->each(function (Crawler $tr) use (&$payouts) {
                try {
                    $th = $tr->filter('th')->first();
                    if ($th->count() === 0) return;

                    // 券種ラベル → kindコード
                    $kindLabel = $this->cleanText($th->text(''));
                    $kind = $this->normalizeKindLabel($kindLabel);
                    if (!$kind) return;

                    $tds = $tr->filter('td');
                    if ($tds->count() < 2) return;

                    // 組合せ・払戻金・人気を <br> 区切りで分割
                    $combos    = $this->extractBrLines($tds->eq(0));
                    $amounts   = $tds->count() > 1 ? $this->extractBrLines($tds->eq(1)) : [];
                    $populars  = $tds->count() > 2 ? $this->extractBrLines($tds->eq(2)) : [];

                    foreach ($combos as $i => $rawCombo) {
                        $combo = $this->normalizePayoutCombination($rawCombo, $kind);
                        if ($combo === '') continue;

                        $amountRaw = $amounts[$i] ?? null;
                        $amount = $amountRaw !== null
                            ? (int) preg_replace('/[^\d]/', '', $amountRaw)
                            : null;
                        if (!$amount) continue;

                        $popRaw = $populars[$i] ?? null;
                        $pop = ($popRaw !== null && preg_match('/(\d+)/', $popRaw, $m))
                            ? (int) $m[1] : null;

                        $payouts[] = [
                            'kind'        => $kind,
                            'combination' => $combo,
                            'amount'      => $amount,
                            'popularity'  => $pop,
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::warning('Netkeiba payout row parse failed: ' . $e->getMessage());
                }
            });
        });

        return $payouts;
    }

    /**
     * 払戻ラベル "単勝" "馬連" 等を kindコードへ正規化
     */
    protected function normalizeKindLabel(string $label): ?string
    {
        $label = trim(preg_replace('/\s+/u', '', $label));
        return match ($label) {
            '単勝'         => 'tan',
            '複勝'         => 'fuku',
            '枠連'         => 'waku-ren',
            '馬連'         => 'uma-ren',
            '馬単'         => 'uma-tan',
            'ワイド'       => 'wide',
            '三連複', '3連複' => 'san-fuku',
            '三連単', '3連単' => 'san-tan',
            default        => null,
        };
    }

    /**
     * <br>区切りで複数行を分解
     *  netkeibaの組合せ列は "1 - 5<br>1 - 7<br>5 - 7" のような書式
     */
    protected function extractBrLines(Crawler $td): array
    {
        // <br>を改行に置換した上でcleanText相当の処理
        try {
            $html = $td->html();
        } catch (\Throwable $e) {
            return [];
        }
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<!--.*?-->/su', '', $html);
        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = preg_split('/\r?\n/', $html);
        $out = [];
        foreach ($lines as $l) {
            $l = trim(preg_replace('/[\s\x{3000}]+/u', ' ', $l));
            if ($l !== '') $out[] = $l;
        }
        return $out;
    }

    /**
     * 払戻組合せ文字列を bet_legs.combination と同じ "-" 区切り正規化形式に変換
     *
     *  入力例:
     *      "5"            → "5"           (単勝/複勝)
     *      "1 - 5"        → "1-5"         (馬連/枠連/ワイド)
     *      "5 → 1"        → "5-1"         (馬単・順序保持)
     *      "5 → 1 → 7"    → "5-1-7"       (3連単・順序保持)
     *      "1 - 5 - 7"    → "1-5-7"       (3連複・昇順ソート)
     */
    protected function normalizePayoutCombination(string $raw, string $kind): string
    {
        // 矢印・ハイフン・全角スペース等を統一
        $s = preg_replace('/\s+/u', '', $raw);
        // → や ⇒ などの矢印を "→" に統一
        $s = preg_replace('/[→⇒>]/u', '→', $s);
        // - や − を "-" に統一
        $s = preg_replace('/[-－‐−]/u', '-', $s);

        // 順序ありの券種は "→" で、順不同は "-" で分割
        $orderedKinds = ['tan', 'fuku', 'uma-tan', 'san-tan'];
        $isOrdered = in_array($kind, $orderedKinds, true);

        // 区切り文字でsplit（→と-どちらも吸収）
        $parts = preg_split('/[→\-]/', $s);
        $parts = array_values(array_filter(array_map('intval', $parts), fn($n) => $n > 0));
        if (empty($parts)) return '';

        if (!$isOrdered) {
            sort($parts);
        }
        return implode('-', $parts);
    }

    /**
     * 結果テーブルをパース（ヘッダ行から列マッピングを構築）
     */
    protected function parseResultsTable(Crawler $crawler): array
    {
        $results = [];

        // 候補セレクタを順に試す
        $tables = null;
        foreach (['table.race_table_01', 'table.race_table_old', 'table.race_table', 'table.nk_tb_common'] as $sel) {
            try {
                $tables = $crawler->filter($sel);
                if ($tables->count() > 0) break;
            } catch (\Throwable $e) {}
        }
        if (!$tables || $tables->count() === 0) {
            Log::warning("Netkeiba: result table not found");
            return [];
        }

        $table = $tables->first();

        // ヘッダ行から列名→列インデックスのマップを作成
        $headerMap = [];
        try {
            $headerCells = $table->filter('tr')->first()->filter('th');
            $headerCells->each(function (Crawler $th, $i) use (&$headerMap) {
                $label = $this->cleanText($th->text(''));
                $headerMap[$i] = $this->normalizeHeader($label);
            });
        } catch (\Throwable $e) {}

        if (empty($headerMap)) {
            Log::warning("Netkeiba: header row not detected, fallback to positional parsing");
        }

        // データ行
        $table->filter('tr')->each(function (Crawler $tr, $rowIdx) use (&$results, $headerMap) {
            if ($rowIdx === 0) return; // ヘッダ
            $tds = $tr->filter('td');
            if ($tds->count() < 5) return;

            $row = [];
            $tds->each(function (Crawler $td, $colIdx) use (&$row, $headerMap) {
                $key = $headerMap[$colIdx] ?? null;
                if (!$key) return;

                // リンク内テキストを優先（馬名・騎手名等はaタグでラップされてる）
                // 馬・騎手・調教師リンクからは netkeiba_id も併せて取得
                try {
                    $a = $td->filter('a')->first();
                    if ($a->count() > 0) {
                        $val = $this->cleanText($a->text(''));
                        if ($val !== '') {
                            $row[$key] = $val;
                            $href = $a->attr('href') ?? '';
                            if ($key === 'horse_name' && preg_match('#/horse/(\d+)#', $href, $m)) {
                                $row['horse_netkeiba_id'] = $m[1];
                            } elseif ($key === 'jockey_name' && preg_match('#/jockey/(?:result/)?(\d+)#', $href, $m)) {
                                $row['jockey_netkeiba_id'] = $m[1];
                            } elseif ($key === 'trainer_name' && preg_match('#/trainer/(?:result/)?(\d+)#', $href, $m)) {
                                $row['trainer_netkeiba_id'] = $m[1];
                            }
                            return;
                        }
                    }
                } catch (\Throwable $e) {}

                $row[$key] = $this->cleanText($td->text(''));
            });

            if (empty($row)) return;

            // 整形
            $results[] = $this->normalizeRow($row);
        });

        return $results;
    }

    /**
     * ヘッダラベルを正規化キーに変換
     */
    protected function normalizeHeader(string $label): ?string
    {
        $label = trim($label);
        $map = [
            '着順'    => 'finish_position',
            '枠'      => 'frame_number',
            '枠番'    => 'frame_number',
            '馬番'    => 'horse_number',
            '馬名'    => 'horse_name',
            '性齢'    => 'sex_age',
            '斤量'    => 'weight_carried',
            '騎手'    => 'jockey_name',
            'タイム'  => 'time',
            '着差'    => 'margin',
            '通過'    => 'corner_positions',
            '上り'    => 'last_3f',
            '上がり'  => 'last_3f',
            '人気'    => 'popularity',
            '単勝'    => 'win_odds',
            'オッズ'  => 'win_odds',
            '馬体重'  => 'horse_weight_str',
            '調教師'  => 'trainer_name',
            '厩舎'    => 'trainer_name',
            '性別'    => 'sex',
            '齢'      => 'age',
            '馬主'    => 'owner',
            '賞金(万円)' => 'prize_money',
            '賞金'    => 'prize_money',
        ];
        return $map[$label] ?? null;
    }

    /**
     * 行データを正規化（型変換、複合フィールド分解）
     */
    protected function normalizeRow(array $row): array
    {
        $out = [];

        // 着順: "1", "中", "取消", "除外", "失" など
        if (isset($row['finish_position'])) {
            $out['finish_position'] = mb_substr(trim($row['finish_position']), 0, 5);
        }

        // 枠番・馬番
        if (isset($row['frame_number']))   $out['frame_number'] = $this->parseInt($row['frame_number']);
        if (isset($row['horse_number']))   $out['horse_number'] = $this->parseInt($row['horse_number']) ?? 0;

        // 馬名
        if (isset($row['horse_name']))     $out['horse_name'] = $row['horse_name'];

        // 性齢: "牡4" → sex=牡, age=4
        if (isset($row['sex_age']) && preg_match('/(牡|牝|セ)(\d+)/u', $row['sex_age'], $m)) {
            $out['sex'] = $m[1];
            $out['age'] = (int) $m[2];
        }

        // 斤量
        if (isset($row['weight_carried']) && is_numeric($row['weight_carried'])) {
            $out['weight_carried'] = (float) $row['weight_carried'];
        }

        // 騎手・調教師
        if (isset($row['jockey_name']))    $out['jockey_name'] = $row['jockey_name'];
        if (isset($row['trainer_name']))   $out['trainer_name'] = $row['trainer_name'];

        // netkeiba ID（馬・騎手・調教師）
        if (isset($row['horse_netkeiba_id']))   $out['horse_netkeiba_id']   = $row['horse_netkeiba_id'];
        if (isset($row['jockey_netkeiba_id']))  $out['jockey_netkeiba_id']  = $row['jockey_netkeiba_id'];
        if (isset($row['trainer_netkeiba_id'])) $out['trainer_netkeiba_id'] = $row['trainer_netkeiba_id'];

        // タイム "1:23.4"
        if (isset($row['time']))           $out['time'] = mb_substr($row['time'], 0, 10);

        // 着差: 短い文字列のみ採用
        if (isset($row['margin'])) {
            $margin = trim($row['margin']);
            // 余計な数値・空白が混入してる場合がある → 最大10文字に切り詰め
            $margin = preg_replace('/\s+/u', ' ', $margin);
            $out['margin'] = mb_substr($margin, 0, 10);
        }

        // 通過順 "3-3-2-1"
        if (isset($row['corner_positions'])) {
            $out['corner_positions'] = mb_substr($row['corner_positions'], 0, 30);
        }

        // 上がり3F "34.5"
        if (isset($row['last_3f'])) {
            $out['last_3f'] = mb_substr($row['last_3f'], 0, 6);
        }

        // 人気
        if (isset($row['popularity']) && is_numeric($row['popularity'])) {
            $out['popularity'] = (int) $row['popularity'];
        }

        // 単勝オッズ
        if (isset($row['win_odds']) && is_numeric($row['win_odds'])) {
            $out['win_odds'] = (float) $row['win_odds'];
        }

        // 馬体重 "480(+2)"
        if (isset($row['horse_weight_str']) && preg_match('/(\d+)\s*\(\s*([+\-]?\d+)\s*\)/', $row['horse_weight_str'], $m)) {
            $out['horse_weight'] = (int) $m[1];
            $out['horse_weight_diff'] = (int) $m[2];
        } elseif (isset($row['horse_weight_str']) && preg_match('/(\d+)/', $row['horse_weight_str'], $m)) {
            $out['horse_weight'] = (int) $m[1];
        }

        // 賞金（カンマ含み）
        if (isset($row['prize_money'])) {
            $val = preg_replace('/[^\d.]/', '', $row['prize_money']);
            if ($val !== '') $out['prize_money'] = (int) (float) $val;
        }

        return $out;
    }

    /**
     * race_id と HTML から開催日(YYYY-MM-DD)を厳密に抽出する
     *
     * 抽出ロジック:
     *   1) $expectedDate が渡されており、race_id の年と一致すればそれを最優先で採用
     *   2) 狭い DOM ノードを順番に試す（ヘッダ・サイドバー・広告に含まれる無関係な日付を避ける）
     *      - dl#RaceList_DateList dd.Active
     *      - .RaceList_Item02 .RaceList_Itemtitle
     *      - .RaceData02
     *      - .smalltxt / .race_data dt
     *      - パンくず .Race_Crumb / #breadcrumb
     *      - <title>
     *   3) 各候補から「YYYY年M月D日」を拾い、race_id 先頭4桁の年と一致するものを採用
     *   4) どうしても見つからなければ <body> 全体走査（年が一致するもののみ採用）
     *   5) それでもなければ null（呼び出し側で扱う）
     */
    protected function extractRaceDateFromCrawler(Crawler $crawler, string $raceId, ?string $expectedDate = null): ?string
    {
        $year = substr($raceId, 0, 4);

        // 1) 認証された日付（呼び出し側が知っている本当の開催日）が race_id の年と一致 → 即採用
        if ($expectedDate !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $expectedDate, $em)) {
            if ($em[1] === $year) {
                return $expectedDate;
            }
        }

        // 2) 狭いノードから順に「年が一致する YYYY年M月D日」を探す
        $selectors = [
            'dl#RaceList_DateList dd.Active',
            'dl.RaceList_DateList dd.Active',
            '#RaceList_DateList dd.Active',
            '.RaceList_Item02 .RaceList_Itemtitle',
            '.RaceList_NameBox .RaceData01',
            '.RaceData02',
            '.smalltxt',
            '.race_data dt',
            '.Race_Crumb',
            '#breadcrumb',
            'title',
        ];

        foreach ($selectors as $sel) {
            $text = '';
            try {
                $node = $crawler->filter($sel);
                if ($node->count() > 0) {
                    $text = $this->cleanText($node->first()->text(''));
                }
            } catch (\Throwable $e) {
                $text = '';
            }
            if ($text === '') continue;

            // 年付きが見つかれば年一致を確認
            if (preg_match_all('/(\d{4})年(\d{1,2})月(\d{1,2})日/u', $text, $ms, PREG_SET_ORDER)) {
                foreach ($ms as $m) {
                    if ($m[1] === $year) {
                        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                    }
                }
            }
            // 年なしの「M月D日」だが、狭いノードなのでこれは race_id の年と組み合わせて採用
            if (preg_match('/(\d{1,2})月(\d{1,2})日/u', $text, $m)) {
                return sprintf('%s-%02d-%02d', $year, $m[1], $m[2]);
            }
        }

        // 3) 最後の保険: body 全文から「年が一致する」ものだけを採用
        try {
            $bodyText = $this->cleanText($crawler->filter('body')->text(''));
        } catch (\Throwable $e) {
            $bodyText = '';
        }
        if ($bodyText !== '' && preg_match_all('/(\d{4})年(\d{1,2})月(\d{1,2})日/u', $bodyText, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                if ($m[1] === $year) {
                    return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                }
            }
        }

        return null;
    }

    /**
     * 整数化（失敗時null）
     */
    protected function parseInt($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (int) $v;
        return null;
    }

    /**
     * セレクタからテキスト抽出（複数候補対応）
     */
    protected function extractText(Crawler $crawler, string $selectors): ?string
    {
        foreach (explode(',', $selectors) as $sel) {
            $sel = trim($sel);
            try {
                $node = $crawler->filter($sel)->first();
                if ($node->count() > 0) {
                    $text = $this->cleanText($node->text(''));
                    if ($text !== '') return $text;
                }
            } catch (\Throwable $e) {}
        }
        return null;
    }

    /**
     * テキストクレンジング
     *  - HTMLタグ除去
     *  - 連続空白を1つに
     *  - 前後空白除去
     */
    protected function cleanText(string $text): string
    {
        // HTMLタグ・コメント残骸除去（DomCrawler->text()でも稀に残る）
        $text = preg_replace('/<!--.*?-->/su', '', $text);
        $text = strip_tags($text);
        // HTMLエンティティをデコード
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 連続空白・改行・全角スペースを1つに
        $text = preg_replace('/[\s\x{3000}]+/u', ' ', $text);
        return trim($text);
    }
}
