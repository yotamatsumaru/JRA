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

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('services.netkeiba.base_url'),
            'timeout' => config('services.netkeiba.timeout', 30),
            'headers' => [
                'User-Agent' => config('services.netkeiba.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ja,en;q=0.8',
            ],
            'http_errors' => false,
        ]);
    }

    /**
     * race_id (12桁) からレース結果を取得
     */
    public function fetchRace(string $raceId): array
    {
        $this->respectInterval();

        $url = "/race/{$raceId}/";
        Log::info("Netkeiba fetch: {$url}");

        $response = $this->http->get($url);
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("netkeiba HTTP {$status} for race_id={$raceId}");
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $html = $this->decodeHtml($response->getBody()->getContents(), $contentType);

        // デバッグ用: 取得HTMLを保存（環境変数で有効化）
        if (env('NETKEIBA_DEBUG_SAVE')) {
            $path = storage_path("logs/netkeiba_{$raceId}.html");
            @file_put_contents($path, $html);
            Log::info("Netkeiba HTML saved: {$path}");
        }

        return $this->parseRaceHtml($raceId, $html);
    }

    /**
     * 開催日からレースID一覧を取得
     */
    public function fetchRaceIdsByDate(string $date): array
    {
        $this->respectInterval();

        $ymd = str_replace('-', '', $date);
        $url = "/race/list/{$ymd}/";

        $response = $this->http->get($url);
        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $html = $this->decodeHtml($response->getBody()->getContents(), $contentType);

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
        // smalltxt や race_data に「YYYY年MM月DD日」が入る
        $allText = $this->cleanText($crawler->filter('body')->text(''));
        if (preg_match('/(\d{4})年(\d{1,2})月(\d{1,2})日/u', $allText, $m)) {
            $data['race_date'] = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        } elseif (preg_match('/(\d{1,2})月(\d{1,2})日/u', $allText, $m)) {
            $data['race_date'] = sprintf('%s-%02d-%02d', $year, $m[1], $m[2]);
        }

        // ============ レース詳細（距離・トラック・馬場・天候） ============
        $diaryText = '';
        try {
            $diaryText = $this->cleanText($crawler->filter('.data_intro')->text(''));
        } catch (\Throwable $e) {
            $diaryText = $allText;
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
        if (preg_match('/天候\s*[:：]\s*(\S+?)\s/u', $diaryText, $m)) {
            $data['weather'] = $m[1];
        }
        if (preg_match('/馬場\s*[:：]\s*(\S+?)\s/u', $diaryText, $m)) {
            $data['course_condition'] = $m[1];
        }

        // ============ 結果テーブル ============
        // 現行netkeiba: <table class="race_table_01 nk_tb_common">
        // ヘッダ列の意味で取得（列インデックスではなく見出しベース）
        $results = $this->parseResultsTable($crawler);

        $data['results'] = $results;
        $data['horses_count'] = count($results);

        return $data;
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
                try {
                    $a = $td->filter('a')->first();
                    if ($a->count() > 0) {
                        $val = $this->cleanText($a->text(''));
                        if ($val !== '') {
                            $row[$key] = $val;
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

        // 騎手
        if (isset($row['jockey_name']))    $out['jockey_name'] = $row['jockey_name'];
        if (isset($row['trainer_name']))   $out['trainer_name'] = $row['trainer_name'];

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
