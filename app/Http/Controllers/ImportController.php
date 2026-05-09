<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\ImportLog;
use App\Models\Race;
use App\Services\CsvImportService;
use App\Services\NetkeibaScraper;
use App\Services\OpenAIVisionService;
use App\Services\RaceImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            'race_id'  => ['nullable', 'string', 'regex:/^\d{12}$/'],
            'race_url' => ['nullable', 'url'],
            'mode'     => ['nullable', 'in:result,shutuba'],
        ]);

        $raceId = $validated['race_id'] ?? null;
        if (!$raceId && !empty($validated['race_url'])) {
            if (preg_match('/race[\/_]?(?:id=)?(\d{12})/', $validated['race_url'], $m)) {
                $raceId = $m[1];
            } elseif (preg_match('/race_id=(\d{12})/', $validated['race_url'], $m)) {
                $raceId = $m[1];
            }
        }
        if (!$raceId) {
            return back()->withErrors(['race_id' => 'race_id か netkeibaのURLを入力してください']);
        }

        $mode = $validated['mode'] ?? 'result';
        $isShutuba = ($mode === 'shutuba');

        $log = ImportLog::create([
            'user_id'    => $request->user()->id,
            'source'     => $isShutuba ? 'netkeiba_shutuba' : 'netkeiba',
            'reference'  => ($isShutuba ? 'shutuba/' : 'race/') . $raceId,
            'status'     => 'processing',
            'started_at' => now(),
        ]);

        try {
            if ($isShutuba) {
                $data     = $this->netkeiba->fetchShutuba($raceId);
                $imported = $this->raceImporter->importShutuba($data);
                $statusMsg = '出馬表を取込完了: ' . $imported->full_name . '（' . count($data['results'] ?? []) . '頭）';
            } else {
                $data     = $this->netkeiba->fetchRace($raceId);
                $imported = $this->raceImporter->importFromNetkeiba($data);
                $statusMsg = 'netkeibaから取込完了: ' . $imported->full_name;
            }

            $log->update([
                'status'           => 'success',
                'records_total'    => 1,
                'records_imported' => 1,
                'payload'          => ['race_id_db' => $imported->id, 'mode' => $mode],
                'finished_at'      => now(),
            ]);
            return redirect()->route('races.show', $imported)
                ->with('status', $statusMsg);
        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at'   => now(),
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

    // ─── 進捗ダッシュボード ───────────────────────
    /**
     * バックグラウンド取込の進捗ファイル + DB集計を表示
     * (php artisan netkeiba:year / netkeiba:fill-pedigree が書く JSON を読む)
     */
    public function progress(): View
    {
        $data = $this->buildProgressPayload();
        return view('import.progress', $data);
    }

    /**
     * 自動更新用 JSON エンドポイント
     */
    public function progressJson(): JsonResponse
    {
        return response()->json($this->buildProgressPayload());
    }

    /**
     * 進捗ペイロードを構築(Blade/JSON共通)
     */
    protected function buildProgressPayload(): array
    {
        // ===== netkeiba:year の進捗ファイル一覧 =====
        $yearProgress = [];
        try {
            $files = Storage::files();
            foreach ($files as $file) {
                if (!preg_match('/^netkeiba_year_(\d{4})\.json$/', $file, $m)) continue;
                $year = (int) $m[1];
                $json = json_decode(Storage::get($file), true);
                if (!is_array($json)) continue;

                $doneCount = count($json['done'] ?? []);
                $errorsCount = count($json['errors'] ?? []);
                $yearProgress[] = [
                    'year'       => $year,
                    'success'    => (int) ($json['success'] ?? 0),
                    'failed'     => (int) ($json['failed'] ?? 0),
                    'done_count' => $doneCount,
                    'errors'     => $errorsCount,
                    'updated_at' => $json['updated_at'] ?? null,
                    'file'       => $file,
                ];
            }
            usort($yearProgress, fn($a, $b) => $b['year'] <=> $a['year']);
        } catch (\Throwable $e) {
            // ignore
        }

        // ===== netkeiba:fill-pedigree の進捗ファイル =====
        $pedigreeProgress = null;
        try {
            if (Storage::exists('netkeiba_pedigree_progress.json')) {
                $json = json_decode(Storage::get('netkeiba_pedigree_progress.json'), true);
                if (is_array($json)) {
                    $pedigreeProgress = [
                        'done_count'   => count($json['done'] ?? []),
                        'failed_count' => count($json['failed'] ?? []),
                        'updated_at'   => $json['updated_at'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // ===== DB 側の年別レース数 =====
        $racesByYear = [];
        try {
            $rows = Race::query()
                ->selectRaw('YEAR(race_date) AS y, COUNT(*) AS c')
                ->whereNotNull('race_date')
                ->groupByRaw('YEAR(race_date)')
                ->orderByDesc('y')
                ->limit(20)
                ->get();
            foreach ($rows as $r) {
                $racesByYear[(int) $r->y] = (int) $r->c;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // ===== 馬データの血統入力状況 =====
        $horseStats = [
            'total'              => 0,
            'pedigree_filled'    => 0,
            'pedigree_missing'   => 0,
        ];
        try {
            $horseStats['total'] = Horse::count();
            $horseStats['pedigree_filled'] = Horse::query()
                ->whereNotNull('father')
                ->whereNotNull('mother')
                ->count();
            $horseStats['pedigree_missing'] = $horseStats['total'] - $horseStats['pedigree_filled'];
        } catch (\Throwable $e) {
            // ignore
        }

        // ===== 直近の ImportLog =====
        $recentLogs = [];
        try {
            $recentLogs = ImportLog::query()
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'source', 'reference', 'status', 'records_imported', 'records_failed', 'started_at', 'finished_at'])
                ->map(fn($l) => [
                    'id'        => $l->id,
                    'source'    => $l->source,
                    'reference' => $l->reference,
                    'status'    => $l->status,
                    'imported'  => $l->records_imported,
                    'failed'    => $l->records_failed,
                    'started'   => optional($l->started_at)->format('Y-m-d H:i'),
                    'finished'  => optional($l->finished_at)->format('Y-m-d H:i'),
                ])
                ->all();
        } catch (\Throwable $e) {
            // ignore
        }

        return [
            'yearProgress'     => $yearProgress,
            'pedigreeProgress' => $pedigreeProgress,
            'racesByYear'      => $racesByYear,
            'horseStats'       => $horseStats,
            'recentLogs'       => $recentLogs,
            'generatedAt'      => now()->format('Y-m-d H:i:s'),
        ];
    }
}
