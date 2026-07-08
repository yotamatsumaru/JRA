# 🏇 JRA Analyzer

JRA（中央競馬）データ分析Webアプリケーション。個人利用専用。

## 概要

JRA中央競馬（10場）のレース結果を蓄積・分析するためのLaravel製Webアプリ。
複数の取込手段（手入力／CSV／netkeibaスクレイピング／GPT-4o画像解析）に対応し、
枠順・脚質・血統・騎手×コース相性・回収率などの多角的な分析機能を提供します。

## 主な機能

### データ管理
- ✍️ **手入力**: レース基本情報・出走結果をフォームから登録
- 📄 **CSV取込**: ヘッダ付きCSVでバルクインポート（更新もサポート）
- 🌐 **netkeibaスクレイピング**: 12桁race_idまたはURLから自動取得（5秒間隔のレートリミット）
- 📸 **画像取込（GPT-4o Vision）**: 出馬表・結果票のスクショをAIが解析しデータ化

### 分析
- 📊 **競馬場別傾向**: 枠順×着順ヒートマップ、脚質別成績、距離別フィルタ
- 🏃 **ペース分析**: ハイ/ミドル/スロー × 脚質ピボット
- 🧬 **血統傾向**: 父系別の得意コース・距離抽出
- 🎯 **騎手×コース相性**: 騎手別の競馬場・トラック相性ヒートマップ
- 💰 **回収率シミュレータ**: 人気・場・トラック条件での単勝ROI計算

### その他
- 🏇 **競馬場・馬・騎手ページ**: 個別の出走履歴と統計
- 📝 **メモ機能**: レース・馬に紐づく自由記述メモ（タグ付き）
- 📈 **ダッシュボード**: KPIカード + ApexChartsによる可視化

## 技術スタック

| 項目 | 採用 |
|------|------|
| 言語 | PHP 8.2 |
| FW   | Laravel 11 |
| DB   | MariaDB 10.5 (XServer) |
| 認証 | Laravel Breeze (Blade版) |
| テンプレート | Blade |
| CSS  | Tailwind CSS（CDN） |
| JS   | Alpine.js（CDN） |
| グラフ | ApexCharts（CDN） |
| スクレイピング | Symfony DomCrawler + Guzzle |
| CSV  | league/csv |
| AI   | OpenAI GPT-4o Vision |

> ⚠️ XServerの古いglibcがNode.jsを動かせないため、Vite/Node.jsを使わず<br>
> CDN経由のTailwind/Alpine/ApexChartsで完結する構成になっています。

## ディレクトリ構成

```
JRA/
├── app/
│   ├── Console/Commands/       # netkeiba取込コマンド
│   ├── Http/Controllers/       # 13個のコントローラー
│   ├── Models/                 # 10個のEloquentモデル
│   └── Services/               # スクレイパー/Vision/CSV/Importサービス
├── config/                     # Laravel設定
├── database/
│   ├── migrations/             # 12マイグレーション
│   └── seeders/                # Venue / Jockeyシーダー
├── public/                     # 公開エントリ
├── resources/views/            # Bladeテンプレート（全画面）
├── routes/web.php              # ルート定義
├── scripts/                    # XServerデプロイ補助スクリプト
└── README.md
```

## XServer デプロイ手順

### 1. 環境準備

XServerに以下が必要:
- PHP 8.2 (`php82` コマンドで確認)
- Composer 2.x (`~/composer.phar` 等)
- MariaDB データベース1つ作成
- データベースユーザー1つ作成

### 2. リポジトリ取得

```bash
ssh xs524093@xs524093.xsrv.jp
cd ~/xs524093.xsrv.jp/
git clone https://github.com/yotamatsumaru/JRA.git laravel
cd laravel
```

### 3. 環境変数の設定（非対話）

```bash
cp scripts/.env.local.example scripts/.env.local
# scripts/.env.local をエディタで編集
#  - DB_PASSWORD: 作成済みDBユーザーのパスワード
#  - ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD: 自動作成される管理ユーザー
#  - OPENAI_API_KEY: 画像取込を使う場合のみ
```

### 4. セットアップ自動実行

```bash
bash scripts/setup.sh
```

setup.sh は以下を自動実行します:
1. PHP/Composer/MySQLバージョン確認
2. `.env` 生成（`scripts/.env.local` から値を反映）
3. `php artisan key:generate`
4. `composer install --no-dev --optimize-autoloader`
5. DB接続テスト → マイグレーション → シーダー実行
6. Laravel Breeze (Blade) インストール
7. `@vite` ディレクティブをCDN script tagに置換
8. `~/xs524093.xsrv.jp/public_html/JRA/` への公開ディレクトリ配置
9. キャッシュクリア
10. 管理ユーザー自動作成 + ログインスモークテスト

### 5. ブラウザで確認

`https://xs524093.xsrv.jp/JRA/` にアクセスし、`scripts/.env.local` で指定した管理者でログイン。

## ローカル開発（参考）

ローカルにPHP 8.2 / Composer 2.xがある場合:

```bash
cp .env.example .env
# .env をDB情報・OPENAI_API_KEY等で編集
php artisan key:generate
composer install
php artisan migrate --seed
php artisan serve
```

## 5年分の過去データ取込

サーバーSSHで以下を実行:

```bash
# 単一レース
php artisan netkeiba:race 202405040811

# 指定日のレース全部
php artisan netkeiba:date 2024-05-04

# 期間指定（5年分なら 2020-01-01〜2025-05-08 等）
php artisan netkeiba:date 2020-01-01 --to=2025-05-08 --limit=10000
```

> ⚠️ netkeibaのrobots.txtを尊重し、5秒間隔のリクエスト間隔がデフォルトです。
> `NETKEIBA_REQUEST_INTERVAL` で調整可能。

## 主要なテーブル

| テーブル | 内容 |
|---------|------|
| venues | 競馬場（10場） |
| horses | 馬（父・母・母父・馬主・生産者） |
| jockeys | 騎手（所属、現役フラグ） |
| trainers | 調教師 |
| races | レース基本情報 |
| race_results | 出走結果（着順・タイム・通過順・脚質） |
| race_notes | レース・馬に紐づくユーザーメモ |
| favorites | 馬・騎手のお気に入り（polymorphic） |
| import_logs | 取込履歴（manual/csv/netkeiba/image） |

## 脚質自動判定ロジック

`RaceResult::detectRunningStyle($cornerPositions, $horsesCount)` が判定:

- 1コーナーで先頭 → **逃**
- 1コーナー位置 ÷ 頭数 ≤ 0.3 → **先**
- 〜 0.65 → **差**
- それ以外 → **追**

## 📱 アプリ版(Flutter)向け API

既存のBlade画面(セッション認証)には一切影響を与えず、Laravel Sanctumによる
トークン認証の独立したAPIレイヤーを `routes/api.php` に追加しています。

### セットアップ(サーバー側で1回だけ実行)

```bash
composer install
php artisan migrate   # personal_access_tokens テーブルが追加されます
```

### エンドポイント一覧

| メソッド | パス | 説明 | 認証 |
|---|---|---|---|
| POST | `/api/login` | ログイン(トークン発行) | 不要 |
| POST | `/api/logout` | ログアウト(トークン失効) | 要 |
| GET  | `/api/user` | ログイン中ユーザー情報 | 要 |
| GET  | `/api/venues` | 競馬場一覧 | 要 |
| GET  | `/api/races` | レース一覧(結果確定分) | 要 |
| GET  | `/api/races/{race}` | レース詳細・結果 | 要 |
| GET  | `/api/shutuba` | 出馬表一覧(予想対象レース) | 要 |
| GET  | `/api/shutuba/{race}` | 出馬表詳細・印・スコア・EV | 要 |
| POST | `/api/shutuba/{race}/mark` | 印を付ける(◎○▲△☆✕) | 要 |
| POST | `/api/shutuba/{race}/auto-mark` | 印を自動提案 | 要 |
| POST | `/api/shutuba/{race}/memo` | メモ保存 | 要 |
| GET  | `/api/shutuba/{race}/odds-timeline` | オッズ推移(グラフ用) | 要 |
| POST | `/api/shutuba/{race}/capture-odds` | 最新オッズを取得 | 要 |
| GET  | `/api/analytics/venue` | 競馬場別傾向分析 | 要 |
| GET  | `/api/analytics/roi` | 回収率シミュレーション | 要 |

### 認証方法

```
POST /api/login
Content-Type: application/json
Accept: application/json

{ "email": "you@example.com", "password": "xxxx", "device_name": "iPhone 15" }

→ { "ok": true, "token": "1|xxxxxxxx...", "user": { "id": 1, "name": "...", "email": "..." } }
```

以降のリクエストには以下のヘッダーを付与してください:

```
Authorization: Bearer {token}
Accept: application/json
```

`Accept: application/json` を付けない場合、通常のBlade画面用リクエストとして
扱われるため、必ず付与してください。

## ライセンス

個人利用のためライセンス指定なし。
