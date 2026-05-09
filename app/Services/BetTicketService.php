<?php

namespace App\Services;

use App\Models\Bet;
use App\Models\BetLeg;
use App\Models\Payout;
use App\Models\Race;
use Illuminate\Support\Facades\DB;

/**
 * 馬券の組合せ展開・的中判定を担当するサービス
 *
 * 提供機能:
 *   - expandCombinations(): 入力（軸/相手/ボックス）→ 買い目組合せ配列
 *   - isOrdered(): 券種が順序を持つか
 *   - settle(): レース確定後に bet_legs を判定し bets サマリを更新
 *   - normalizeCombination(): 馬番リストを正規化された "-" 区切り文字列に
 */
class BetTicketService
{
    /** 馬番系券種で必要な頭数 */
    public const KIND_SIZE = [
        'tan'      => 1,
        'fuku'     => 1,
        'waku-ren' => 2,
        'uma-ren'  => 2,
        'uma-tan'  => 2,
        'wide'     => 2,
        'san-fuku' => 3,
        'san-tan'  => 3,
    ];

    /** 順序ありの券種（順序が違うと別の組合せ） */
    public const ORDERED_KINDS = ['tan', 'fuku', 'uma-tan', 'san-tan'];

    public function isOrdered(string $kind): bool
    {
        return in_array($kind, self::ORDERED_KINDS, true);
    }

    public function getSize(string $kind): int
    {
        return self::KIND_SIZE[$kind] ?? 1;
    }

    /**
     * 入力データから買い目の組合せ配列を生成
     *
     * @param string $kind   券種
     * @param string $method single|box|formation
     * @param array  $selection
     *   single   : ['numbers' => [3,7]]            // 直接組合せ
     *   box      : ['numbers' => [1,3,5,7]]        // この中から券種サイズ分の全組合せ
     *   formation: ['axis'=>[3], 'second'=>[1,5,7], 'third'=>[1,5,7,9]]
     *              （3連単/3連複は3列、馬連/馬単/ワイドは axis+second の2列）
     *
     * @return string[]  正規化された組合せ文字列の配列
     */
    public function expandCombinations(string $kind, string $method, array $selection): array
    {
        $size = $this->getSize($kind);
        $ordered = $this->isOrdered($kind);

        $rawTuples = match ($method) {
            'single'    => $this->expandSingle($selection, $size, $ordered),
            'box'       => $this->expandBox($selection, $size, $ordered),
            'formation' => $this->expandFormation($selection, $kind, $ordered),
            default     => [],
        };

        // 正規化 + 重複除去
        $unique = [];
        foreach ($rawTuples as $tuple) {
            $combo = $this->normalizeCombination($tuple, $ordered);
            if ($combo !== '') $unique[$combo] = true;
        }

        return array_keys($unique);
    }

    /**
     * 馬番配列を組合せ文字列に正規化
     *  - 順不同: 昇順ソート → "-" 連結
     *  - 順序あり: 入力順そのまま → "-" 連結
     *  - 重複馬番は除去
     */
    public function normalizeCombination(array $numbers, bool $ordered): string
    {
        $numbers = array_values(array_filter(array_map('intval', $numbers), fn($n) => $n > 0));
        if (empty($numbers)) return '';

        if (!$ordered) {
            $numbers = array_values(array_unique($numbers));
            sort($numbers);
        } else {
            // 順序ありでも同一馬番が連続するのは無効
            if (count($numbers) !== count(array_unique($numbers))) return '';
        }
        return implode('-', $numbers);
    }

    /** single: そのまま1点 */
    protected function expandSingle(array $sel, int $size, bool $ordered): array
    {
        $nums = $sel['numbers'] ?? [];
        if (count($nums) !== $size) return [];
        return [$nums];
    }

    /** box: 全組合せ（順不同のみ。順序あり券種でも順列展開） */
    protected function expandBox(array $sel, int $size, bool $ordered): array
    {
        $pool = array_values(array_unique(array_map('intval', $sel['numbers'] ?? [])));
        $pool = array_values(array_filter($pool, fn($n) => $n > 0));
        if (count($pool) < $size) return [];

        return $ordered
            ? $this->permutations($pool, $size)
            : $this->combinations($pool, $size);
    }

    /**
     * formation: 軸/相手/3列目の直積。同一馬の重複は除外。
     *   2頭立て券種: axis × second
     *   3頭立て券種: axis × second × third
     *   3連単などは順序あり（1着→2着→3着）
     *   馬単は axis(1着) × second(2着)
     */
    protected function expandFormation(array $sel, string $kind, bool $ordered): array
    {
        $size = $this->getSize($kind);
        $axis   = $this->cleanList($sel['axis']   ?? []);
        $second = $this->cleanList($sel['second'] ?? []);
        $third  = $this->cleanList($sel['third']  ?? []);

        if (empty($axis) || empty($second)) return [];
        if ($size === 3 && empty($third)) return [];

        $tuples = [];
        if ($size === 2) {
            foreach ($axis as $a) {
                foreach ($second as $b) {
                    if ($a === $b) continue;
                    $tuples[] = [$a, $b];
                }
            }
        } elseif ($size === 3) {
            foreach ($axis as $a) {
                foreach ($second as $b) {
                    if ($a === $b) continue;
                    foreach ($third as $c) {
                        if ($c === $a || $c === $b) continue;
                        $tuples[] = [$a, $b, $c];
                    }
                }
            }
        } else {
            // size=1（単勝/複勝）はaxisリストそれぞれ1点
            foreach ($axis as $a) $tuples[] = [$a];
        }

        return $tuples;
    }

    protected function cleanList(array $list): array
    {
        $out = array_values(array_unique(array_map('intval', $list)));
        return array_values(array_filter($out, fn($n) => $n > 0));
    }

    /** nCr 全組合せ */
    protected function combinations(array $arr, int $r): array
    {
        if ($r === 0) return [[]];
        if (count($arr) < $r) return [];
        if (count($arr) === $r) return [$arr];

        [$head, $rest] = [$arr[0], array_slice($arr, 1)];
        $with = array_map(fn($c) => array_merge([$head], $c), $this->combinations($rest, $r - 1));
        $without = $this->combinations($rest, $r);
        return array_merge($with, $without);
    }

    /** nPr 全順列 */
    protected function permutations(array $arr, int $r): array
    {
        if ($r === 0) return [[]];
        $out = [];
        foreach ($arr as $i => $v) {
            $rest = $arr;
            array_splice($rest, $i, 1);
            foreach ($this->permutations($rest, $r - 1) as $tail) {
                $out[] = array_merge([$v], $tail);
            }
        }
        return $out;
    }

    // ======================================================
    //  的中判定 & 精算
    // ======================================================

    /**
     * Bet を精算する
     *  - レース結果から「正解の組合せ」を構築
     *  - bet_legs を1点ずつ判定 → is_hit / payout を更新
     *  - bets のサマリ列を更新
     *
     * @return array ['hit_count'=>int, 'total_return'=>int]
     */
    public function settle(Bet $bet): array
    {
        $bet->loadMissing(['race.results', 'legs']);
        $race = $bet->race;
        if (!$race) return ['hit_count' => 0, 'total_return' => 0];

        $winners = $this->buildWinningCombinations($race, $bet->kind);
        // 公式払戻データ: combination → ['amount'=>x, 'popularity'=>y]
        $payoutMap = $this->loadPayoutMap($race->id, $bet->kind);

        $hitCount = 0;
        $totalReturn = 0;

        foreach ($bet->legs as $leg) {
            $isHit = in_array($leg->combination, $winners, true);
            $payout = 0;
            $pop = null;

            if ($isHit) {
                if (isset($payoutMap[$leg->combination])) {
                    $unit = $payoutMap[$leg->combination]['amount'];   // 100円あたり
                    $pop  = $payoutMap[$leg->combination]['popularity'];
                    $payout = (int) round($leg->stake * $unit / 100);
                }
                // payoutMap が無い場合（払戻未取込）は payout=0、的中フラグのみ立てる
            }

            $leg->is_hit = $isHit;
            $leg->payout = $payout;
            $leg->payout_popularity = $pop;
            $leg->save();

            if ($isHit) {
                $hitCount++;
                $totalReturn += $payout;
            }
        }

        $bet->hit_count    = $hitCount;
        $bet->total_return = $totalReturn;
        $bet->is_settled   = true;
        $bet->save();

        return ['hit_count' => $hitCount, 'total_return' => $totalReturn];
    }

    /**
     * レース結果から「正解の組合せ」リストを構築
     *  - 単勝: 1着の馬番
     *  - 複勝: 1〜3着の各馬番（3点）
     *  - 馬連: 1-2着の昇順
     *  - 馬単: 1-2着の順序通り
     *  - ワイド: 1-2,1-3,2-3 の3点
     *  - 3連複: 1-2-3着の昇順
     *  - 3連単: 1-2-3着の順序通り
     *  - 枠連: 1-2着の枠番（昇順）。frame_number があれば
     */
    public function buildWinningCombinations(Race $race, string $kind): array
    {
        $top3 = $race->results
            ->whereIn('finish_position_int', [1, 2, 3])
            ->sortBy('finish_position_int')
            ->values();
        if ($top3->isEmpty()) return [];

        $byPos = [
            1 => $top3->firstWhere('finish_position_int', 1),
            2 => $top3->firstWhere('finish_position_int', 2),
            3 => $top3->firstWhere('finish_position_int', 3),
        ];

        $hn = fn($r) => $r ? (int) $r->horse_number : null;
        $fr = fn($r) => $r ? (int) $r->frame_number : null;

        $h1 = $hn($byPos[1]); $h2 = $hn($byPos[2]); $h3 = $hn($byPos[3]);

        return match ($kind) {
            'tan'      => $h1 ? [(string) $h1] : [],
            'fuku'     => array_values(array_filter([
                $h1 ? (string) $h1 : null,
                $h2 ? (string) $h2 : null,
                $h3 ? (string) $h3 : null,
            ])),
            'uma-ren'  => ($h1 && $h2) ? [$this->sortKey([$h1, $h2])] : [],
            'uma-tan'  => ($h1 && $h2) ? ["{$h1}-{$h2}"] : [],
            'wide'     => array_values(array_filter([
                ($h1 && $h2) ? $this->sortKey([$h1, $h2]) : null,
                ($h1 && $h3) ? $this->sortKey([$h1, $h3]) : null,
                ($h2 && $h3) ? $this->sortKey([$h2, $h3]) : null,
            ])),
            'san-fuku' => ($h1 && $h2 && $h3) ? [$this->sortKey([$h1, $h2, $h3])] : [],
            'san-tan'  => ($h1 && $h2 && $h3) ? ["{$h1}-{$h2}-{$h3}"] : [],
            'waku-ren' => ($fr($byPos[1]) && $fr($byPos[2]))
                ? [$this->sortKey([$fr($byPos[1]), $fr($byPos[2])])]
                : [],
            default    => [],
        };
    }

    protected function sortKey(array $nums): string
    {
        sort($nums);
        return implode('-', $nums);
    }

    /** payouts テーブルから race × kind の払戻マップを取得 */
    protected function loadPayoutMap(int $raceId, string $kind): array
    {
        return Payout::where('race_id', $raceId)
            ->where('kind', $kind)
            ->get()
            ->keyBy('combination')
            ->map(fn($p) => ['amount' => (int) $p->amount, 'popularity' => $p->popularity])
            ->toArray();
    }
}
