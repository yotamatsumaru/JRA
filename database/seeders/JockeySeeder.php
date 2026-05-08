<?php

namespace Database\Seeders;

use App\Models\Jockey;
use Illuminate\Database\Seeder;

/**
 * JRA主要騎手マスタ（2025-2026年シーズン現役騎手の代表的な選手）
 * 必要に応じて netkeiba スクレイピング後に自動拡張されます
 */
class JockeySeeder extends Seeder
{
    public function run(): void
    {
        $jockeys = [
            // 関東(美浦)
            ['name' => 'ルメール',    'name_kana' => 'クリストフ・ルメール', 'belonging' => 'フリー'],
            ['name' => '横山武史',    'name_kana' => 'ヨコヤマタケシ',     'belonging' => '美浦'],
            ['name' => '横山和生',    'name_kana' => 'ヨコヤマカズオ',     'belonging' => '美浦'],
            ['name' => '横山典弘',    'name_kana' => 'ヨコヤマノリヒロ',   'belonging' => '美浦'],
            ['name' => '戸崎圭太',    'name_kana' => 'トサキケイタ',       'belonging' => '美浦'],
            ['name' => '田辺裕信',    'name_kana' => 'タナベヒロノブ',     'belonging' => '美浦'],
            ['name' => '三浦皇成',    'name_kana' => 'ミウラコウセイ',     'belonging' => '美浦'],
            ['name' => '木幡巧也',    'name_kana' => 'コハタタクヤ',       'belonging' => '美浦'],
            ['name' => '津村明秀',    'name_kana' => 'ツムラアキヒデ',     'belonging' => '美浦'],
            ['name' => '丹内祐次',    'name_kana' => 'タンナイユウジ',     'belonging' => '美浦'],
            ['name' => '菅原明良',    'name_kana' => 'スガワラアキラ',     'belonging' => '美浦'],
            ['name' => '佐々木大輔',  'name_kana' => 'ササキダイスケ',     'belonging' => '美浦'],
            ['name' => '永野猛蔵',    'name_kana' => 'ナガノタケゾウ',     'belonging' => '美浦'],

            // 関西(栗東)
            ['name' => '川田将雅',    'name_kana' => 'カワダユウガ',       'belonging' => '栗東'],
            ['name' => '武豊',        'name_kana' => 'タケユタカ',         'belonging' => '栗東'],
            ['name' => '坂井瑠星',    'name_kana' => 'サカイルセイ',       'belonging' => '栗東'],
            ['name' => '松山弘平',    'name_kana' => 'マツヤマコウヘイ',   'belonging' => '栗東'],
            ['name' => '岩田望来',    'name_kana' => 'イワタミライ',       'belonging' => '栗東'],
            ['name' => '岩田康誠',    'name_kana' => 'イワタヤスナリ',     'belonging' => '栗東'],
            ['name' => '幸英明',      'name_kana' => 'ミユキヒデアキ',     'belonging' => '栗東'],
            ['name' => '池添謙一',    'name_kana' => 'イケゾエケンイチ',   'belonging' => '栗東'],
            ['name' => '福永祐一',    'name_kana' => 'フクナガユウイチ',   'belonging' => '栗東', 'is_active' => false],
            ['name' => '浜中俊',      'name_kana' => 'ハマナカスグル',     'belonging' => '栗東'],
            ['name' => '北村友一',    'name_kana' => 'キタムラユウイチ',   'belonging' => '栗東'],
            ['name' => '吉田隼人',    'name_kana' => 'ヨシダハヤト',       'belonging' => '栗東'],
            ['name' => '和田竜二',    'name_kana' => 'ワダリュウジ',       'belonging' => '栗東'],
            ['name' => '鮫島克駿',    'name_kana' => 'サメシマカツトシ',   'belonging' => '栗東'],
            ['name' => '団野大成',    'name_kana' => 'ダンノタイセイ',     'belonging' => '栗東'],
            ['name' => '角田大河',    'name_kana' => 'ツノダタイガ',       'belonging' => '栗東'],
            ['name' => '西村淳也',    'name_kana' => 'ニシムラジュンヤ',   'belonging' => '栗東'],

            // 短期免許で来日することの多い外国人騎手
            ['name' => 'モレイラ',    'name_kana' => 'ジョアン・モレイラ', 'belonging' => '外国'],
            ['name' => 'マーカンド',  'name_kana' => 'トム・マーカンド',   'belonging' => '外国'],
            ['name' => 'デムーロ',    'name_kana' => 'ミルコ・デムーロ',   'belonging' => 'フリー'],
        ];

        foreach ($jockeys as $j) {
            $j['is_active'] = $j['is_active'] ?? true;
            Jockey::updateOrCreate(['name' => $j['name']], $j);
        }
    }
}
