<?php

namespace Database\Seeders;

use App\Models\Venue;
use Illuminate\Database\Seeder;

/**
 * JRA中央競馬場 10場のマスタデータ
 */
class VenueSeeder extends Seeder
{
    public function run(): void
    {
        $venues = [
            [
                'code' => '01', 'name' => '札幌', 'name_kana' => 'サッポロ',
                'region' => '北海道', 'direction' => '右',
                'turf_straight' => 266, 'dirt_straight' => 264,
                'characteristics' => '洋芝、平坦、直線短い。先行有利の傾向。',
            ],
            [
                'code' => '02', 'name' => '函館', 'name_kana' => 'ハコダテ',
                'region' => '北海道', 'direction' => '右',
                'turf_straight' => 262, 'dirt_straight' => 260,
                'characteristics' => '洋芝、平坦、直線最短クラス。内枠・先行が有利。',
            ],
            [
                'code' => '03', 'name' => '福島', 'name_kana' => 'フクシマ',
                'region' => '東北', 'direction' => '右',
                'turf_straight' => 292, 'dirt_straight' => 295,
                'characteristics' => '小回り、直線短い。逃げ・先行が圧倒的有利。',
            ],
            [
                'code' => '04', 'name' => '新潟', 'name_kana' => 'ニイガタ',
                'region' => '甲信越', 'direction' => '左',
                'turf_straight' => 659, 'dirt_straight' => 354,
                'characteristics' => '芝直線競馬あり。直線長く差し・追込も決まる。',
            ],
            [
                'code' => '05', 'name' => '東京', 'name_kana' => 'トウキョウ',
                'region' => '関東', 'direction' => '左',
                'turf_straight' => 525, 'dirt_straight' => 501,
                'characteristics' => '直線長く坂あり。実力勝負。差し・末脚型に向く。',
            ],
            [
                'code' => '06', 'name' => '中山', 'name_kana' => 'ナカヤマ',
                'region' => '関東', 'direction' => '右',
                'turf_straight' => 310, 'dirt_straight' => 308,
                'characteristics' => '小回り、急坂あり。先行・パワー型有利。',
            ],
            [
                'code' => '07', 'name' => '中京', 'name_kana' => 'チュウキョウ',
                'region' => '東海', 'direction' => '左',
                'turf_straight' => 412, 'dirt_straight' => 410,
                'characteristics' => '坂あり、直線そこそこ長い。瞬発力勝負になりやすい。',
            ],
            [
                'code' => '08', 'name' => '京都', 'name_kana' => 'キョウト',
                'region' => '関西', 'direction' => '右',
                'turf_straight' => 404, 'dirt_straight' => 329,
                'characteristics' => '外回り直線404m。淀の坂、内ラチ沿いのバイアスに注意。',
            ],
            [
                'code' => '09', 'name' => '阪神', 'name_kana' => 'ハンシン',
                'region' => '関西', 'direction' => '右',
                'turf_straight' => 474, 'dirt_straight' => 353,
                'characteristics' => '内回り/外回り。外回り直線は急坂あり。差し届く。',
            ],
            [
                'code' => '10', 'name' => '小倉', 'name_kana' => 'コクラ',
                'region' => '九州', 'direction' => '右',
                'turf_straight' => 293, 'dirt_straight' => 291,
                'characteristics' => '小回り平坦。逃げ・先行が圧倒的に有利。',
            ],
        ];

        foreach ($venues as $venue) {
            Venue::updateOrCreate(['code' => $venue['code']], $venue);
        }
    }
}
