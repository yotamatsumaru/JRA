<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI GPT-4o Vision API サービス
 *
 * 競馬の出馬表・レース結果スクショから構造化データを抽出
 */
class OpenAIVisionService
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 60,
        ]);
    }

    /**
     * 画像から競馬データを抽出
     *
     * @param string $base64 画像のbase64エンコード
     * @param string $mime  image/jpeg, image/png 等
     * @param string $mode  race_result or race_card
     * @return array
     */
    public function extract(string $base64, string $mime, string $mode = 'race_result'): array
    {
        $apiKey = config('services.openai.api_key');
        if (!$apiKey) {
            throw new \RuntimeException('OPENAI_API_KEY が未設定です');
        }

        $prompt = $this->buildPrompt($mode);

        $payload = [
            'model' => config('services.openai.model', 'gpt-4o'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'あなたは日本の競馬データを正確にJSON形式で抽出するエキスパートです。出力は必ず純粋なJSONオブジェクトのみとし、説明文は一切含めないでください。',
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mime};base64,{$base64}",
                                'detail' => 'high',
                            ],
                        ],
                    ],
                ],
            ],
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 4000,
            'temperature' => 0.1,
        ];

        $response = $this->http->post(config('services.openai.endpoint'), [
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        $content = $body['choices'][0]['message']['content'] ?? '{}';

        Log::info('OpenAI Vision raw response', ['content' => $content]);

        $data = json_decode($content, true);
        if (!$data) {
            throw new \RuntimeException('Vision API のレスポンスをJSONとしてパースできませんでした: ' . $content);
        }

        return $data;
    }

    protected function buildPrompt(string $mode): string
    {
        if ($mode === 'race_card') {
            return <<<PROMPT
この画像はJRA中央競馬の出馬表です。以下のJSON形式で正確に抽出してください。
データが画像にない項目は null を返してください。

{
  "race": {
    "name": "レース名",
    "venue_name": "競馬場名（例:東京、中山）",
    "race_date": "YYYY-MM-DD",
    "race_number": "R番号(整数1-12)",
    "track_type": "芝/ダート/障害",
    "distance": "距離(整数,m)",
    "course_condition": "良/稍重/重/不良",
    "weather": "晴/曇/雨/雪等",
    "grade": "G1/G2/G3/L/OP/3勝/2勝/1勝/未勝利/新馬"
  },
  "results": [
    {
      "horse_number": "馬番(整数)",
      "frame_number": "枠番(整数1-8)",
      "horse_name": "馬名",
      "sex": "牡/牝/セ",
      "age": "年齢(整数)",
      "weight_carried": "斤量(kg、小数可)",
      "jockey_name": "騎手名",
      "trainer_name": "調教師名",
      "popularity": "人気(整数)",
      "win_odds": "単勝オッズ(数値)"
    }
  ]
}
PROMPT;
        }

        return <<<PROMPT
この画像はJRA中央競馬のレース結果です。以下のJSON形式で正確に抽出してください。
データが画像にない項目は null を返してください。

{
  "race": {
    "name": "レース名",
    "venue_name": "競馬場名",
    "race_date": "YYYY-MM-DD",
    "race_number": "R番号(整数)",
    "track_type": "芝/ダート/障害",
    "distance": "距離(整数,m)",
    "course_condition": "良/稍重/重/不良",
    "weather": "天候",
    "grade": "G1/G2/G3/L/OP/3勝/2勝/1勝/未勝利/新馬"
  },
  "results": [
    {
      "finish_position": "着順(1-18 / 中止/取消/除外/失格)",
      "horse_number": "馬番(整数)",
      "frame_number": "枠番(整数1-8)",
      "horse_name": "馬名",
      "sex": "牡/牝/セ",
      "age": "年齢(整数)",
      "weight_carried": "斤量(kg)",
      "jockey_name": "騎手名",
      "trainer_name": "調教師名",
      "time": "タイム(例:1:23.4)",
      "margin": "着差(例:1/2、クビ、アタマ)",
      "last_3f": "上がり3F(例:34.5)",
      "corner_positions": "通過順(例:3-3-2-1)",
      "popularity": "単勝人気(整数)",
      "win_odds": "単勝オッズ(数値)",
      "horse_weight": "馬体重(整数,kg)",
      "horse_weight_diff": "馬体重増減(整数)"
    }
  ]
}
PROMPT;
    }
}
