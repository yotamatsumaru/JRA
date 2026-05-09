<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * DBビューア (読み取り専用)
 *
 * - テーブル一覧 + 件数 + 主要メタ
 * - 各テーブルのプレビュー (直近100行 + 検索)
 * - スキーマ図 (Mermaid)
 * - 統計ダッシュボード (ApexCharts)
 *
 * セキュリティ:
 *  - auth ミドルウェアで保護
 *  - SELECT のみ。UPDATE/DELETE/INSERT は提供しない
 *  - テーブル名はホワイトリスト経由でしか触れない (SQLインジェクション防止)
 */
class DbViewerController extends Controller
{
    /**
     * 表示対象テーブル(ホワイトリスト) と表示メタ
     *
     * order: 並び順
     * label: 画面用ラベル
     * group: 分類グループ
     * primary_order: プレビュー時のデフォルト並び順
     */
    private const TABLES = [
        // === マスタ系 ===
        'venues'         => ['order' => 10, 'label' => '競馬場',           'group' => 'マスタ',     'primary_order' => 'code'],
        'venue_courses'  => ['order' => 11, 'label' => 'コース傾向マスタ', 'group' => 'マスタ',     'primary_order' => 'venue_id'],
        'horses'         => ['order' => 20, 'label' => '馬',               'group' => 'マスタ',     'primary_order' => 'id'],
        'jockeys'        => ['order' => 21, 'label' => '騎手',             'group' => 'マスタ',     'primary_order' => 'id'],
        'trainers'       => ['order' => 22, 'label' => '調教師',           'group' => 'マスタ',     'primary_order' => 'id'],
        // === レース系 ===
        'races'          => ['order' => 30, 'label' => 'レース',           'group' => 'レース',     'primary_order' => 'race_date'],
        'race_results'   => ['order' => 31, 'label' => 'レース結果',       'group' => 'レース',     'primary_order' => 'id'],
        'payouts'        => ['order' => 32, 'label' => '払戻',             'group' => 'レース',     'primary_order' => 'id'],
        // === ユーザー系 ===
        'users'          => ['order' => 40, 'label' => 'ユーザー',         'group' => 'ユーザー',   'primary_order' => 'id'],
        'race_notes'     => ['order' => 41, 'label' => 'メモ',             'group' => 'ユーザー',   'primary_order' => 'id'],
        'favorites'      => ['order' => 42, 'label' => 'お気に入り',       'group' => 'ユーザー',   'primary_order' => 'id'],
        'bets'           => ['order' => 43, 'label' => '馬券',             'group' => 'ユーザー',   'primary_order' => 'id'],
        'bet_legs'       => ['order' => 44, 'label' => '馬券明細',         'group' => 'ユーザー',   'primary_order' => 'id'],
        // === システム ===
        'import_logs'    => ['order' => 50, 'label' => 'インポートログ',   'group' => 'システム',   'primary_order' => 'id'],
    ];

    /**
     * テーブル間の関係 (ER図用)
     *
     * [from_table, from_col, to_table, to_col, label]
     */
    private const RELATIONS = [
        ['venue_courses', 'venue_id',  'venues',   'id', 'belongsTo'],
        ['races',         'venue_id',  'venues',   'id', 'belongsTo'],
        ['race_results',  'race_id',   'races',    'id', 'belongsTo'],
        ['race_results',  'horse_id',  'horses',   'id', 'belongsTo'],
        ['race_results',  'jockey_id', 'jockeys',  'id', 'belongsTo'],
        ['race_results',  'trainer_id','trainers', 'id', 'belongsTo'],
        ['payouts',       'race_id',   'races',    'id', 'belongsTo'],
        ['race_notes',    'user_id',   'users',    'id', 'belongsTo'],
        ['race_notes',    'race_id',   'races',    'id', 'belongsTo'],
        ['race_notes',    'horse_id',  'horses',   'id', 'belongsTo'],
        ['favorites',     'user_id',   'users',    'id', 'belongsTo'],
        ['bets',          'user_id',   'users',    'id', 'belongsTo'],
        ['bets',          'race_id',   'races',    'id', 'belongsTo'],
        ['bet_legs',      'bet_id',    'bets',     'id', 'belongsTo'],
        ['import_logs',   'user_id',   'users',    'id', 'belongsTo'],
    ];

    /**
     * トップ画面: テーブル一覧 + 行数バーチャート
     */
    public function index(): View
    {
        $rows = [];
        foreach (self::TABLES as $name => $meta) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            $cols = Schema::getColumnListing($name);
            $cnt  = (int) DB::table($name)->count();

            $latestUpdate = null;
            if (in_array('updated_at', $cols, true)) {
                $latestUpdate = DB::table($name)->max('updated_at');
            } elseif (in_array('created_at', $cols, true)) {
                $latestUpdate = DB::table($name)->max('created_at');
            }

            $rows[] = (object) [
                'name'          => $name,
                'label'         => $meta['label'],
                'group'         => $meta['group'],
                'order'         => $meta['order'],
                'rows'          => $cnt,
                'columns'       => count($cols),
                'latest_update' => $latestUpdate,
            ];
        }

        usort($rows, fn($a, $b) => $a->order <=> $b->order);

        $grouped = collect($rows)->groupBy('group');

        $totalRows    = collect($rows)->sum('rows');
        $totalTables  = count($rows);
        $totalColumns = collect($rows)->sum('columns');

        return view('admin.db.index', [
            'rows'        => $rows,
            'grouped'     => $grouped,
            'totalRows'   => $totalRows,
            'totalTables' => $totalTables,
            'totalColumns'=> $totalColumns,
        ]);
    }

    /**
     * テーブル詳細: スキーマ + 直近100行
     */
    public function table(Request $request, string $table): View
    {
        $table = $this->resolveTable($table);
        $meta  = self::TABLES[$table];

        $columns   = Schema::getColumnListing($table);
        $colDetail = $this->getColumnDetails($table);
        $perPage   = (int) $request->get('per_page', 50);
        $perPage   = max(10, min(200, $perPage));
        $orderBy   = $request->get('order_by', $meta['primary_order'] ?? 'id');
        $orderDir  = $request->get('order_dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $keyword   = trim((string) $request->get('q', ''));

        if (! in_array($orderBy, $columns, true)) {
            $orderBy = $columns[0] ?? 'id';
        }

        $query = DB::table($table);

        // キーワード検索 (テキスト系カラムに OR LIKE)
        if ($keyword !== '') {
            $query->where(function ($q) use ($columns, $colDetail, $keyword) {
                foreach ($columns as $col) {
                    $type = $colDetail[$col]['type'] ?? '';
                    if ($this->isTextType($type)) {
                        $q->orWhere($col, 'like', '%' . $keyword . '%');
                    }
                }
            });
        }

        $totalRows = (clone $query)->count();
        $items = $query->orderBy($orderBy, $orderDir)
            ->limit($perPage)
            ->get();

        // 関連リンク (foreign key)
        $fkLinks = [];
        foreach (self::RELATIONS as [$ft, $fc, $tt, $tc]) {
            if ($ft === $table) {
                $fkLinks[$fc] = $tt;
            }
        }

        $tableMeta = [
            'name'        => $table,
            'label'       => $meta['label'],
            'group'       => $meta['group'],
            'columns'     => $columns,
            'col_detail'  => $colDetail,
            'fk_links'    => $fkLinks,
        ];

        return view('admin.db.table', [
            'tableMeta' => $tableMeta,
            'items'     => $items,
            'totalRows' => $totalRows,
            'perPage'   => $perPage,
            'orderBy'   => $orderBy,
            'orderDir'  => $orderDir,
            'keyword'   => $keyword,
            'tables'    => $this->tableSidebar(),
        ]);
    }

    /**
     * スキーマ図 (Mermaid)
     */
    public function schema(): View
    {
        $entities = [];
        foreach (self::TABLES as $name => $meta) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            $colDetail = $this->getColumnDetails($name);
            $entities[$name] = [
                'label'   => $meta['label'],
                'group'   => $meta['group'],
                'columns' => $colDetail,
            ];
        }

        return view('admin.db.schema', [
            'entities'  => $entities,
            'relations' => self::RELATIONS,
            'tables'    => $this->tableSidebar(),
        ]);
    }

    /**
     * 統計ダッシュボード
     */
    public function stats(): View
    {
        // ===== テーブル別行数 =====
        $rowCounts = [];
        foreach (self::TABLES as $name => $meta) {
            if (! Schema::hasTable($name)) continue;
            $rowCounts[] = [
                'name'  => $name,
                'label' => $meta['label'],
                'group' => $meta['group'],
                'rows'  => (int) DB::table($name)->count(),
            ];
        }
        usort($rowCounts, fn($a, $b) => $b['rows'] <=> $a['rows']);

        // ===== 月別 races 増加 =====
        $racesByMonth = [];
        if (Schema::hasTable('races') && Schema::hasColumn('races', 'race_date')) {
            $racesByMonth = DB::table('races')
                ->selectRaw("DATE_FORMAT(race_date, '%Y-%m') as ym, COUNT(*) as cnt")
                ->whereNotNull('race_date')
                ->groupBy('ym')
                ->orderBy('ym')
                ->get()
                ->map(fn($r) => ['ym' => $r->ym, 'cnt' => (int) $r->cnt])
                ->all();
        }

        // ===== race_results を venue ごとに =====
        $resultsByVenue = [];
        if (Schema::hasTable('race_results') && Schema::hasTable('races') && Schema::hasTable('venues')) {
            $resultsByVenue = DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->join('venues', 'venues.id', '=', 'races.venue_id')
                ->selectRaw('venues.name as venue, COUNT(*) as cnt')
                ->groupBy('venues.name')
                ->orderByDesc('cnt')
                ->get()
                ->map(fn($r) => ['venue' => $r->venue, 'cnt' => (int) $r->cnt])
                ->all();
        }

        // ===== トラック別 races =====
        $racesByTrack = [];
        if (Schema::hasTable('races') && Schema::hasColumn('races', 'track_type')) {
            $racesByTrack = DB::table('races')
                ->selectRaw('track_type, COUNT(*) as cnt')
                ->whereNotNull('track_type')
                ->groupBy('track_type')
                ->get()
                ->map(fn($r) => ['track' => $r->track_type, 'cnt' => (int) $r->cnt])
                ->all();
        }

        // ===== グループ別行数 =====
        $byGroup = [];
        foreach ($rowCounts as $r) {
            $byGroup[$r['group']] = ($byGroup[$r['group']] ?? 0) + $r['rows'];
        }

        // ===== 日次 race_results 推移(直近90日) =====
        $resultsByDay = [];
        if (Schema::hasTable('race_results') && Schema::hasTable('races')) {
            $resultsByDay = DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->whereDate('races.race_date', '>=', now()->subDays(90)->toDateString())
                ->selectRaw("DATE_FORMAT(races.race_date, '%Y-%m-%d') as d, COUNT(*) as cnt")
                ->groupBy('d')
                ->orderBy('d')
                ->get()
                ->map(fn($r) => ['d' => $r->d, 'cnt' => (int) $r->cnt])
                ->all();
        }

        return view('admin.db.stats', [
            'rowCounts'       => $rowCounts,
            'racesByMonth'    => $racesByMonth,
            'resultsByVenue'  => $resultsByVenue,
            'racesByTrack'    => $racesByTrack,
            'byGroup'         => $byGroup,
            'resultsByDay'    => $resultsByDay,
            'tables'          => $this->tableSidebar(),
        ]);
    }

    // ====================================================================
    // ヘルパー
    // ====================================================================

    /**
     * テーブル名をホワイトリストで検証して返す
     */
    private function resolveTable(string $table): string
    {
        if (! array_key_exists($table, self::TABLES)) {
            abort(404, "Unknown table: {$table}");
        }
        if (! Schema::hasTable($table)) {
            abort(404, "Table does not exist in database: {$table}");
        }
        return $table;
    }

    /**
     * カラム詳細メタを取得 (型/null可否)
     *
     * Laravel/Doctrine の差異を吸収するため、information_schema を直接見る。
     */
    private function getColumnDetails(string $table): array
    {
        $details = [];
        try {
            $rows = DB::select(
                'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, COLUMN_COMMENT
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
                [$table]
            );
            foreach ($rows as $r) {
                $details[$r->COLUMN_NAME] = [
                    'type'     => $r->COLUMN_TYPE,
                    'nullable' => $r->IS_NULLABLE === 'YES',
                    'key'      => $r->COLUMN_KEY,
                    'default'  => $r->COLUMN_DEFAULT,
                    'comment'  => $r->COLUMN_COMMENT,
                ];
            }
        } catch (\Throwable $e) {
            // フォールバック: カラム名のみ
            foreach (Schema::getColumnListing($table) as $col) {
                $details[$col] = [
                    'type' => '?', 'nullable' => true, 'key' => '', 'default' => null, 'comment' => '',
                ];
            }
        }
        return $details;
    }

    private function isTextType(string $type): bool
    {
        $type = strtolower($type);
        return str_contains($type, 'char')
            || str_contains($type, 'text')
            || str_contains($type, 'enum');
    }

    /**
     * サイドバー用テーブル一覧
     */
    private function tableSidebar(): array
    {
        $list = [];
        foreach (self::TABLES as $name => $meta) {
            if (! Schema::hasTable($name)) continue;
            $list[] = (object) [
                'name'  => $name,
                'label' => $meta['label'],
                'group' => $meta['group'],
                'order' => $meta['order'],
            ];
        }
        usort($list, fn($a, $b) => $a->order <=> $b->order);
        return $list;
    }
}
