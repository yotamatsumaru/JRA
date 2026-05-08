# JRA App セットアップスクリプト

XServer 上で Laravel 11 + Breeze (Blade) + Tailwind/Alpine/ApexCharts(CDN) を一括セットアップするスクリプト群です。

---

## 📁 ファイル構成

| ファイル | 役割 |
|---------|------|
| `.env.local.example` | 設定ファイルのテンプレート |
| `.env.local` | 実値（DB情報、GitHub Token等）※Git管理外 |
| `setup.sh` | 一括セットアップスクリプト |
| `push-to-github.sh` | GitHub Push スクリプト |
| `README.md` | このファイル |

---

## 🚀 使い方

### STEP 1: `.env.local` を作成

```bash
cd ~/xs524093.xsrv.jp/laravel/scripts
cp .env.local.example .env.local
vi .env.local
```

以下の項目に実値を入れてください：

```bash
# DB情報
DB_PASSWORD=（変更後の新しいDBパスワード）

# 管理者ユーザー
ADMIN_NAME=Yota Matsumaru
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=（任意のパスワード、8文字以上）

# GitHub Personal Access Token
GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

#### Personal Access Token の取得方法

1. GitHub にログイン
2. Settings → Developer settings → Personal access tokens → **Tokens (classic)**
3. **「Generate new token (classic)」** をクリック
4. 以下を設定:
   - **Note**: `JRA-App XServer Deploy`
   - **Expiration**: 90 days（適宜）
   - **Scopes**: `repo` にチェック（Privateリポジトリへのpushに必要）
5. 「Generate token」→ 表示された `ghp_xxx...` をコピー
6. `.env.local` の `GITHUB_TOKEN=` に貼り付け

⚠️ **トークンは一度しか表示されません！必ずコピーしてください。**

---

### STEP 2: 一括セットアップ実行

```bash
cd ~/xs524093.xsrv.jp/laravel/
bash scripts/setup.sh
```

このスクリプトが以下をすべて自動実行します：

- Phase 0: 前提チェック
- Phase 1: `.env` ファイル生成 + APP_KEY
- Phase 2: `composer install`
- Phase 3: DB 接続テスト
- Phase 4: マイグレーション
- Phase 5: Breeze (Blade版) インストール
- Phase 6: Bladeレイアウトを CDN化（Tailwind/Alpine/ApexCharts）
- Phase 7: 公開ディレクトリ (`JRA/`) 設定
- Phase 8: キャッシュ最適化
- Phase 9: 管理者ユーザー作成
- Phase 10: HTTP動作確認

エラーで止まった場合は、メッセージを確認して該当 Phase から再実行してください。

---

### STEP 3: ブラウザで確認

```
https://xs524093.xsrv.jp/JRA/login
```

`.env.local` で設定した管理者の Email/Password でログインできれば成功 🎉

---

### STEP 4: GitHub に Push

```bash
cd ~/xs524093.xsrv.jp/laravel/
bash scripts/push-to-github.sh
```

または、コミットメッセージを指定して：

```bash
bash scripts/push-to-github.sh "feat: add race result form"
```

---

## 🔒 セキュリティに関する注意

- `.env.local` には **DBパスワード** や **GitHub Token** が入ります
- `.gitignore` で除外されているため Git にはコミットされません
- ファイルパーミッションを必ず制限してください:
  ```bash
  chmod 600 scripts/.env.local
  ```

---

## 🛠 トラブルシューティング

### `Composer が見つかりません`
`.bashrc` に以下を追加:
```bash
alias composer='/usr/bin/php8.2 /home/xs524093/composer.phar'
```

### `DB 接続失敗`
- `.env.local` の DB情報を再確認
- XServer サーバーパネルで MySQL ユーザーがDBにアクセス権を持っているか確認

### `500 Internal Server Error`
```bash
chmod -R 755 ~/xs524093.xsrv.jp/laravel/storage
chmod -R 755 ~/xs524093.xsrv.jp/laravel/bootstrap/cache
tail -50 ~/xs524093.xsrv.jp/laravel/storage/logs/laravel.log
```

### `404 Not Found`
- `~/xs524093.xsrv.jp/public_html/JRA/.htaccess` の `RewriteBase /JRA/` を確認

### Push に失敗
- `GITHUB_TOKEN` の有効期限・権限（`repo` スコープ）を確認
- リポジトリ `https://github.com/yotamatsumaru/JRA` が存在するか確認

---

## 📦 構築される技術スタック

| レイヤー | 技術 |
|---------|------|
| バックエンド | Laravel 11 + PHP 8.2 |
| 認証 | Laravel Breeze (Blade版) |
| テンプレート | Blade |
| 動的UI | Alpine.js (CDN) |
| CSS | Tailwind CSS (CDN) |
| グラフ | ApexCharts (CDN) |
| DB | MariaDB 10.5 |
