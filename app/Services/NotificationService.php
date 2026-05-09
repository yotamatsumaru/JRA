<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\PredictionShare;
use App\Models\Race;
use App\Models\RaceResult;
use App\Models\Watchlist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * 通知サービス (Phase 6-A)
 *
 *  - ウォッチリスト対象の出走予定をスキャンし、まだ通知していなければ通知レコードを生成
 *  - 共有予想の有効期限切れ間近 (24時間以内) を通知
 *  - last_alerted_at でレース単位の重複通知を抑止
 */
class NotificationService
{
    /**
     * ユーザーの通知を一括スキャン (Dashboard / 通知ページから呼び出す)
     *
     * @return int 新規生成した通知数
     */
    public function scanForUser(int $userId, int $days = 3): int
    {
        if (!Schema::hasTable('app_notifications')) return 0;

        $created = 0;
        $created += $this->scanWatchlistEntries($userId, $days);
        $created += $this->scanExpiringShares($userId);
        return $created;
    }

    /**
     * ウォッチリスト対象が今日〜N日以内に出走予定なら通知を生成
     */
    public function scanWatchlistEntries(int $userId, int $days = 3): int
    {
        if (!Schema::hasTable('watchlists')) return 0;

        $watchlist = Watchlist::where('user_id', $userId)
            ->where('alert_on_entry', true)
            ->get();
        if ($watchlist->isEmpty()) return 0;

        $horseIds   = $watchlist->where('target_type', 'horse')->pluck('target_id')->map(fn($v) => (int) $v)->all();
        $jockeyIds  = $watchlist->where('target_type', 'jockey')->pluck('target_id')->map(fn($v) => (int) $v)->all();
        $trainerIds = $watchlist->where('target_type', 'trainer')->pluck('target_id')->map(fn($v) => (int) $v)->all();

        // 全部空ならスキャン対象なし (防御)
        if (empty($horseIds) && empty($jockeyIds) && empty($trainerIds)) return 0;

        $today = Carbon::today();
        $end   = $today->copy()->addDays($days);

        $rrQuery = RaceResult::query()
            ->with(['horse:id,name', 'jockey:id,name', 'trainer:id,name', 'race:id,name,venue_id,race_date,race_number', 'race.venue:id,name'])
            ->whereHas('race', fn($q) => $q->whereBetween('race_date', [$today->toDateString(), $end->toDateString()]))
            ->where(function ($q) use ($horseIds, $jockeyIds, $trainerIds) {
                $applied = false;
                if (!empty($horseIds))   { $q->orWhereIn('horse_id',   $horseIds);   $applied = true; }
                if (!empty($jockeyIds))  { $q->orWhereIn('jockey_id',  $jockeyIds);  $applied = true; }
                if (!empty($trainerIds)) { $q->orWhereIn('trainer_id', $trainerIds); $applied = true; }
                if (!$applied) { $q->whereRaw('0 = 1'); }
            })
            ->get();

        $created = 0;
        $byRace = [];
        foreach ($rrQuery as $rr) {
            $byRace[$rr->race_id][] = $rr;
        }

        foreach ($byRace as $raceId => $rrs) {
            /** @var Race|null $race */
            $race = $rrs[0]->race ?? null;
            if (!$race) continue;

            $hits = [];
            $watchedIds = [];
            foreach ($rrs as $rr) {
                if (in_array($rr->horse_id, $horseIds, true)) {
                    $hits[] = '🐎 '.($rr->horse?->name ?? '#'.$rr->horse_id);
                    $w = $watchlist->firstWhere(fn($w) => $w->target_type === 'horse' && (int)$w->target_id === (int)$rr->horse_id);
                    if ($w) $watchedIds[] = $w->id;
                }
                if (in_array($rr->jockey_id, $jockeyIds, true)) {
                    $hits[] = '👤 '.($rr->jockey?->name ?? '#'.$rr->jockey_id);
                    $w = $watchlist->firstWhere(fn($w) => $w->target_type === 'jockey' && (int)$w->target_id === (int)$rr->jockey_id);
                    if ($w) $watchedIds[] = $w->id;
                }
                if (in_array($rr->trainer_id, $trainerIds, true)) {
                    $hits[] = '🏠 '.($rr->trainer?->name ?? '#'.$rr->trainer_id);
                    $w = $watchlist->firstWhere(fn($w) => $w->target_type === 'trainer' && (int)$w->target_id === (int)$rr->trainer_id);
                    if ($w) $watchedIds[] = $w->id;
                }
            }
            if (empty($hits)) continue;

            // 既に同じ race について通知済みかチェック (payload->race_id)
            $exists = AppNotification::where('user_id', $userId)
                ->where('type', 'watchlist_entry')
                ->where('payload->race_id', $raceId)
                ->exists();
            if ($exists) continue;

            $venue   = $race->venue?->name ?? '';
            $dateStr = $race->race_date?->format('n/j') ?? '';
            $title   = sprintf('[%s] %s%sR ウォッチ対象が出走予定', $dateStr, $venue, $race->race_number);

            AppNotification::create([
                'user_id' => $userId,
                'type'    => 'watchlist_entry',
                'title'   => $title,
                'body'    => implode(' / ', array_unique($hits)),
                'link'    => route('races.show', $race->id, false),
                'payload' => [
                    'race_id' => $raceId,
                    'hits'    => array_values(array_unique($hits)),
                ],
            ]);
            $created++;

            // 関連 watchlist の last_alerted_at を更新
            if (!empty($watchedIds)) {
                Watchlist::whereIn('id', array_unique($watchedIds))->update(['last_alerted_at' => now()]);
            }
        }

        return $created;
    }

    /**
     * 共有予想の期限切れ間近 (24h以内) を通知
     */
    public function scanExpiringShares(int $userId): int
    {
        if (!Schema::hasTable('prediction_shares')) return 0;

        $now = Carbon::now();
        $soon = $now->copy()->addHours(24);

        $shares = PredictionShare::where('user_id', $userId)
            ->where('is_active', true)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now, $soon])
            ->get();

        $created = 0;
        foreach ($shares as $share) {
            $exists = AppNotification::where('user_id', $userId)
                ->where('type', 'share_expiring')
                ->where('payload->share_id', $share->id)
                ->exists();
            if ($exists) continue;

            $title = '共有予想の有効期限が近づいています';
            $body  = sprintf('「%s」は %s に失効します。',
                $share->title ?? '共有予想',
                optional($share->expires_at)->format('Y/m/d H:i') ?? '?');

            AppNotification::create([
                'user_id' => $userId,
                'type'    => 'share_expiring',
                'title'   => $title,
                'body'    => $body,
                'link'    => null,
                'payload' => ['share_id' => $share->id],
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * ヘッダーのベルアイコンに表示する未読件数
     */
    public function unreadCount(int $userId): int
    {
        if (!Schema::hasTable('app_notifications')) return 0;
        return AppNotification::forUser($userId)->unread()->count();
    }

    /**
     * 直近の通知 (ドロップダウン用)
     */
    public function recent(int $userId, int $limit = 5)
    {
        if (!Schema::hasTable('app_notifications')) return collect();
        return AppNotification::forUser($userId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
