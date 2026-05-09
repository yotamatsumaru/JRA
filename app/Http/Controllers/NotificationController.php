<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 通知センター (Phase 6-A)
 */
class NotificationController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    /** 通知一覧 */
    public function index(Request $request)
    {
        $userId = (int) Auth::id();

        // 表示前にスキャン (軽量: 既読/未読は変えず、新規のみ追加)
        try {
            $this->notify->scanForUser($userId, 3);
        } catch (\Throwable $e) {
            // 失敗しても画面表示は続行
        }

        $filter = $request->query('filter', 'all'); // all|unread
        $q = AppNotification::forUser($userId)->orderByDesc('id');
        if ($filter === 'unread') $q->whereNull('read_at');

        $notifications = $q->paginate(30)->withQueryString();
        $unread = $this->notify->unreadCount($userId);

        return view('notifications.index', compact('notifications', 'unread', 'filter'));
    }

    /** 単一通知を既読にし link へリダイレクト */
    public function read(Request $request, AppNotification $notification)
    {
        abort_unless($notification->user_id === Auth::id(), 403);
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }
        if ($notification->link) {
            return redirect($notification->link);
        }
        return redirect()->route('notifications.index');
    }

    /** すべて既読化 */
    public function readAll(Request $request)
    {
        $userId = (int) Auth::id();
        AppNotification::forUser($userId)->whereNull('read_at')->update(['read_at' => now()]);
        return redirect()->route('notifications.index')->with('success', 'すべて既読にしました');
    }

    /** 削除 */
    public function destroy(AppNotification $notification)
    {
        abort_unless($notification->user_id === Auth::id(), 403);
        $notification->delete();
        return redirect()->route('notifications.index')->with('success', '通知を削除しました');
    }

    /** 手動スキャン */
    public function scan()
    {
        $userId = (int) Auth::id();
        $created = $this->notify->scanForUser($userId, 7);
        return redirect()->route('notifications.index')
            ->with('success', sprintf('通知をスキャンしました (新規: %d件)', $created));
    }
}
