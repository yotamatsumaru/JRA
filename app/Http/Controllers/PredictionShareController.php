<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PredictionShare;
use App\Models\Race;
use App\Models\RaceMark;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 予想スナップショット共有 (Phase 4-S)
 *
 *  - レースの印・スコア・メモをスナップショット化し、token URL で共有
 *  - share.show は guest 可 (read-only)
 */
class PredictionShareController extends Controller
{
    /** ユーザーの作成済み共有一覧 */
    public function index(): View
    {
        $shares = PredictionShare::with(['race.venue'])
            ->where('user_id', Auth::id())
            ->orderByDesc('id')
            ->paginate(30);
        return view('shares.index', compact('shares'));
    }

    /** 共有作成フォーム → 即作成 (POST) */
    public function store(Request $request, Race $race): RedirectResponse
    {
        $data = $request->validate([
            'title'      => ['nullable', 'string', 'max:200'],
            'comment'    => ['nullable', 'string', 'max:5000'],
            'expires_in' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $userId = Auth::id();
        $snapshot = $this->buildSnapshot($race, $userId);

        $share = PredictionShare::create([
            'user_id'    => $userId,
            'race_id'    => $race->id,
            'token'      => PredictionShare::generateToken(),
            'title'      => $data['title']   ?? ($race->name . ' の予想'),
            'comment'    => $data['comment'] ?? null,
            'snapshot'   => $snapshot,
            'expires_at' => !empty($data['expires_in']) ? now()->addDays((int) $data['expires_in']) : null,
            'is_active'  => true,
        ]);

        try {
            AuditLog::record('share.create', $share, [
                'race_id' => $race->id, 'token' => $share->token,
            ]);
        } catch (\Throwable $e) { /* ignore */ }

        return redirect()->route('shares.index')
            ->with('status', '共有URLを発行しました: ' . $share->public_url);
    }

    /** 共有を無効化 / 再有効化 */
    public function toggle(PredictionShare $share): RedirectResponse
    {
        $this->authorize($share);
        $share->update(['is_active' => !$share->is_active]);
        try {
            AuditLog::record('share.toggle', $share, ['is_active' => $share->is_active]);
        } catch (\Throwable $e) {}
        return back()->with('status', $share->is_active ? '共有を再開しました' : '共有を停止しました');
    }

    /** 共有を削除 */
    public function destroy(PredictionShare $share): RedirectResponse
    {
        $this->authorize($share);
        $share->delete();
        try { AuditLog::record('share.delete', null, ['id' => $share->id]); } catch (\Throwable $e) {}
        return back()->with('status', '共有を削除しました');
    }

    /** 公開閲覧 (guest 可) */
    public function show(string $token): View
    {
        $share = PredictionShare::where('token', $token)->firstOrFail();
        abort_unless($share->isViewable(), 410, 'この共有は無効化または期限切れです');

        // ビュー回数・最終閲覧時刻を更新 (高頻度なので失敗しても無視)
        try {
            $share->increment('view_count');
            $share->update(['last_viewed_at' => now()]);
        } catch (\Throwable $e) {}

        return view('shares.show', compact('share'));
    }

    /** 印・スコア・メモのスナップショット構築 */
    protected function buildSnapshot(Race $race, int $userId): array
    {
        $race->load(['venue', 'results.horse', 'results.jockey', 'results.trainer']);

        $marks = RaceMark::where('user_id', $userId)
            ->where('race_id', $race->id)
            ->get()
            ->keyBy('race_result_id');

        $rows = [];
        foreach ($race->results as $rr) {
            $m = $marks->get($rr->id);
            $rows[] = [
                'horse_no'      => $rr->horse_number,
                'frame_no'      => $rr->frame_number,
                'horse_name'    => $rr->horse?->name,
                'jockey_name'   => $rr->jockey?->name,
                'trainer_name'  => $rr->trainer?->name,
                'sex_age'       => trim(($rr->sex ?? '') . ($rr->age ?? '')),
                'weight'        => $rr->weight_carried,
                'win_odds'      => $rr->win_odds,
                'popularity'    => $rr->popularity,
                'mark'          => $m?->mark,
                'memo'          => $m?->memo,
                'score_total'   => $m?->score_total,
                'finish_position' => $rr->finish_position_int,
            ];
        }
        usort($rows, fn($a, $b) => ($a['horse_no'] ?? 999) <=> ($b['horse_no'] ?? 999));

        return [
            'race' => [
                'id'         => $race->id,
                'name'       => $race->name,
                'race_date'  => $race->race_date?->format('Y-m-d'),
                'venue'      => $race->venue?->name,
                'race_no'    => $race->race_number,
                'track_type' => $race->track_type,
                'distance'   => $race->distance,
                'condition'  => $race->course_condition,
                'grade'      => $race->grade,
            ],
            'rows' => $rows,
            'created_at' => now()->toIso8601String(),
        ];
    }

    protected function authorize(PredictionShare $s): void
    {
        abort_unless($s->user_id === Auth::id(), 403);
    }
}
