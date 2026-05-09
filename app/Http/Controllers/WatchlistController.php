<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Horse;
use App\Models\Jockey;
use App\Models\Trainer;
use App\Models\Watchlist;
use App\Services\WatchlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * ウォッチリスト (Phase 4-W)
 *  注目馬・騎手・厩舎を登録し、出走予定/直近成績を一覧表示
 */
class WatchlistController extends Controller
{
    public function __construct(protected WatchlistService $svc) {}

    public function index(Request $request): View
    {
        $userId = Auth::id();
        $items   = $this->svc->listFor($userId);
        $upcoming = $this->svc->upcomingEntries($userId, (int) $request->input('days', 7));

        return view('watchlist.index', compact('items', 'upcoming'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'target_type' => ['required', 'in:horse,jockey,trainer'],
            'target_id'   => ['required', 'integer'],
            'memo'        => ['nullable', 'string', 'max:2000'],
            'alert_on_entry' => ['nullable', 'boolean'],
        ]);

        $cls = Watchlist::classFor($data['target_type']);
        if (!$cls) return back()->withErrors(['target_type' => '不正なタイプです']);

        $target = $cls::find($data['target_id']);
        if (!$target) return back()->withErrors(['target_id' => '対象が見つかりません']);

        $w = Watchlist::updateOrCreate(
            [
                'user_id'     => Auth::id(),
                'target_type' => $data['target_type'],
                'target_id'   => $data['target_id'],
            ],
            [
                'label'          => $target->name ?? null,
                'memo'           => $data['memo'] ?? null,
                'alert_on_entry' => (bool) ($data['alert_on_entry'] ?? true),
            ]
        );

        $this->audit('watchlist.add', $w, [
            'target_type' => $data['target_type'], 'target_id' => $data['target_id'],
        ]);

        return back()->with('status', 'ウォッチリストに追加しました: ' . ($target->name ?? '#'.$target->id));
    }

    public function update(Request $request, Watchlist $watchlist): RedirectResponse
    {
        $this->authorize($watchlist);
        $data = $request->validate([
            'memo'           => ['nullable', 'string', 'max:2000'],
            'alert_on_entry' => ['nullable', 'boolean'],
        ]);
        $watchlist->update([
            'memo'           => $data['memo'] ?? null,
            'alert_on_entry' => (bool) ($data['alert_on_entry'] ?? false),
        ]);
        $this->audit('watchlist.update', $watchlist, $data);
        return back()->with('status', 'ウォッチリストを更新しました');
    }

    public function destroy(Watchlist $watchlist): RedirectResponse
    {
        $this->authorize($watchlist);
        $info = ['target_type' => $watchlist->target_type, 'target_id' => $watchlist->target_id];
        $watchlist->delete();
        $this->audit('watchlist.remove', null, $info);
        return back()->with('status', 'ウォッチリストから削除しました');
    }

    protected function authorize(Watchlist $w): void
    {
        abort_unless($w->user_id === Auth::id(), 403);
    }

    protected function audit(string $action, $subject, array $meta): void
    {
        try { AuditLog::record($action, $subject, $meta); } catch (\Throwable $e) { /* ignore */ }
    }
}
