<?php

namespace App\Http\Controllers;

use App\Models\ImportLog;
use App\Services\CsvImportService;
use App\Services\NetkeibaScraper;
use App\Services\OpenAIVisionService;
use App\Services\RaceImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function __construct(
        protected NetkeibaScraper $netkeiba,
        protected OpenAIVisionService $vision,
        protected CsvImportService $csv,
        protected RaceImportService $raceImporter,
    ) {}

    public function index(): View
    {
        $recentLogs = ImportLog::with('user')
            ->orderByDesc('id')
            ->limit(15)
            ->get();
        return view('import.index', compact('recentLogs'));
    }

    // ─── CSV ─────────────────────────────────────
    public function csvForm(): View
    {
        return view('import.csv');
    }

    public function csvStore(Request $request): RedirectResponse
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $log = ImportLog::create([
            'user_id' => $request->user()->id,
            'source' => 'csv',
            'reference' => $request->file('csv_file')->getClientOriginalName(),
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            $result = $this->csv->import($request->file('csv_file')->getRealPath());
            $log->update([
                'status' => $result['failed'] > 0 ? 'partial' : 'success',
                'records_total' => $result['total'],
                'records_imported' => $result['imported'],
                'records_skipped' => $result['skipped'],
                'records_failed' => $result['failed'],
                'finished_at' => now(),
            ]);
            return redirect()->route('import.logs')
                ->with('status', "CSVインポート完了: 取込{$result['imported']}件 / スキップ{$result['skipped']}件 / 失敗{$result['failed']}件");
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            return back()->withErrors(['csv_file' => 'インポートに失敗しました: ' . $e->getMessage()]);
        }
    }

    // ─── netkeiba ────────────────────────────────
    public function netkeibaForm(): View
    {
        return view('import.netkeiba');
    }

    public function netkeibaStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'race_id' => ['nullable', 'string', 'regex:/^\d{12}$/'],
            'race_url' => ['nullable', 'url'],
        ]);

        $raceId = $validated['race_id'] ?? null;
        if (!$raceId && !empty($validated['race_url'])) {
            if (preg_match('/race\/(\d{12})/', $validated['race_url'], $m)) {
                $raceId = $m[1];
            }
        }
        if (!$raceId) {
            return back()->withErrors(['race_id' => 'race_id か netkeibaのURLを入力してください']);
        }

        $log = ImportLog::create([
            'user_id' => $request->user()->id,
            'source' => 'netkeiba',
            'reference' => "race/{$raceId}",
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            $data = $this->netkeiba->fetchRace($raceId);
            $imported = $this->raceImporter->importFromNetkeiba($data);
            $log->update([
                'status' => 'success',
                'records_total' => 1,
                'records_imported' => 1,
                'payload' => ['race_id_db' => $imported->id],
                'finished_at' => now(),
            ]);
            return redirect()->route('races.show', $imported)
                ->with('status', 'netkeibaから取込完了: ' . $imported->full_name);
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            return back()->withErrors(['race_id' => '取込失敗: ' . $e->getMessage()]);
        }
    }

    // ─── 画像（GPT-4o Vision） ───────────────────
    public function imageForm(): View
    {
        return view('import.image');
    }

    public function imageStore(Request $request): RedirectResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:10240'],
            'mode' => ['required', 'in:race_result,race_card'],
        ]);

        if (!config('services.openai.api_key')) {
            return back()->withErrors(['image' => 'OPENAI_API_KEY が未設定です。.env を確認してください。']);
        }

        $log = ImportLog::create([
            'user_id' => $request->user()->id,
            'source' => 'image',
            'reference' => $request->file('image')->getClientOriginalName(),
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            $base64 = base64_encode(file_get_contents($request->file('image')->getRealPath()));
            $mime = $request->file('image')->getMimeType();
            $data = $this->vision->extract($base64, $mime, $request->mode);

            // セッションに一時保存して確認画面を表示する想定
            session(['pending_import' => $data]);

            $log->update([
                'status' => 'success',
                'payload' => $data,
                'records_total' => count($data['results'] ?? []),
                'finished_at' => now(),
            ]);

            return redirect()->route('import.logs')
                ->with('status', '画像解析完了。データを確認してください: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            return back()->withErrors(['image' => '画像解析失敗: ' . $e->getMessage()]);
        }
    }

    // ─── ログ一覧 ────────────────────────────────
    public function logs(): View
    {
        $logs = ImportLog::with('user')
            ->orderByDesc('id')
            ->paginate(30);
        return view('import.logs', compact('logs'));
    }
}
