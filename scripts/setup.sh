#!/bin/bash
# ============================================================
# JRA App XServer 一括セットアップスクリプト
# ============================================================
# 実行方法:
#   cd ~/xs524093.xsrv.jp/laravel/
#   bash scripts/setup.sh
# 前提:
#   - scripts/.env.local が用意されていること
#   - Laravel 11 プロジェクト一式が ~/xs524093.xsrv.jp/laravel/ にある
# ============================================================

set -e  # エラーで停止

# 色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info()    { echo -e "${BLUE}[INFO]${NC}  $1"; }
log_ok()      { echo -e "${GREEN}[ OK ]${NC}  $1"; }
log_warn()    { echo -e "${YELLOW}[WARN]${NC}  $1"; }
log_error()   { echo -e "${RED}[FAIL]${NC}  $1"; }
log_section() { echo ""; echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"; echo -e "${BLUE} $1${NC}"; echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"; }

# ============================================================
# Phase 0: 前提チェック
# ============================================================
log_section "Phase 0: 前提チェック"

# Laravel ルートディレクトリの確認
LARAVEL_DIR="$HOME/xs524093.xsrv.jp/laravel"
PUBLIC_DIR="$HOME/xs524093.xsrv.jp/public_html/JRA"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ ! -f "$LARAVEL_DIR/artisan" ]; then
    log_error "Laravel が $LARAVEL_DIR に見つかりません"
    exit 1
fi
log_ok "Laravel ディレクトリ: $LARAVEL_DIR"

if [ ! -d "$PUBLIC_DIR" ]; then
    log_warn "公開ディレクトリ $PUBLIC_DIR が存在しません。作成します。"
    mkdir -p "$PUBLIC_DIR"
fi
log_ok "公開ディレクトリ: $PUBLIC_DIR"

# .env.local の確認
ENV_LOCAL="$SCRIPT_DIR/.env.local"
if [ ! -f "$ENV_LOCAL" ]; then
    log_error ".env.local が見つかりません: $ENV_LOCAL"
    log_info "scripts/.env.local.example をコピーして作成してください:"
    log_info "  cp scripts/.env.local.example scripts/.env.local"
    log_info "  vi scripts/.env.local"
    exit 1
fi
log_ok ".env.local 読み込み: $ENV_LOCAL"

# .env.local 読み込み
set -a
source "$ENV_LOCAL"
set +a

# 必須変数チェック
REQUIRED_VARS=(DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD ADMIN_NAME ADMIN_EMAIL ADMIN_PASSWORD APP_NAME APP_URL)
for v in "${REQUIRED_VARS[@]}"; do
    if [ -z "${!v}" ]; then
        log_error ".env.local の $v が空です"
        exit 1
    fi
done
log_ok "必須環境変数すべて設定済み"

# PHP / Composer バージョン
PHP_BIN=$(which php)
PHP_VERSION=$(php -r "echo PHP_VERSION;")
log_ok "PHP: $PHP_VERSION ($PHP_BIN)"

if ! command -v composer &> /dev/null; then
    if [ -f "$HOME/composer.phar" ]; then
        alias composer="php $HOME/composer.phar"
        shopt -s expand_aliases
    else
        log_error "Composer が見つかりません"
        exit 1
    fi
fi
COMPOSER_VERSION=$(composer --version 2>&1 | head -1)
log_ok "$COMPOSER_VERSION"

cd "$LARAVEL_DIR"

# ============================================================
# Phase 1: .env ファイル生成
# ============================================================
log_section "Phase 1: .env ファイル生成"

# 既存 .env のバックアップ
if [ -f .env ]; then
    cp .env ".env.backup.$(date +%Y%m%d_%H%M%S)"
    log_ok "既存 .env をバックアップしました"
fi

cat > .env <<EOF
APP_NAME="${APP_NAME}"
APP_ENV=${APP_ENV:-production}
APP_KEY=
APP_DEBUG=${APP_DEBUG:-true}
APP_URL=${APP_URL}

APP_LOCALE=ja
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=ja_JP

APP_TIMEZONE=Asia/Tokyo

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="\${APP_NAME}"

VITE_APP_NAME="\${APP_NAME}"

OPENAI_API_KEY=${OPENAI_API_KEY}
EOF

log_ok ".env ファイル生成完了"

# APP_KEY 生成
php artisan key:generate --force
log_ok "APP_KEY 生成完了"

# ============================================================
# Phase 2: Composer 依存関係インストール
# ============================================================
log_section "Phase 2: Composer 依存関係インストール"

composer install --no-interaction --optimize-autoloader
log_ok "composer install 完了"

# ============================================================
# Phase 3: DB 接続テスト
# ============================================================
log_section "Phase 3: DB 接続テスト"

if php artisan db:show 2>&1 | grep -q "Database"; then
    log_ok "DB 接続成功"
else
    log_error "DB 接続失敗。.env.local の DB情報を確認してください"
    php artisan db:show || true
    exit 1
fi

# ============================================================
# Phase 4: マイグレーション
# ============================================================
log_section "Phase 4: マイグレーション"

php artisan migrate --force
log_ok "マイグレーション完了"

# ============================================================
# Phase 5: Breeze (Blade版) インストール
# ============================================================
log_section "Phase 5: Breeze (Blade版) インストール"

# 既にインストール済みかチェック
if [ -f "routes/auth.php" ] && grep -q "BreezeServiceProvider\|breeze:install" composer.json 2>/dev/null; then
    log_warn "Breeze は既にインストール済みのようです。スキップします。"
else
    if ! grep -q "laravel/breeze" composer.json; then
        composer require laravel/breeze --dev --no-interaction
        log_ok "Breeze パッケージインストール"
    fi

    # Blade版でインストール（非対話）
    php artisan breeze:install blade --no-interaction || \
    php artisan breeze:install blade
    log_ok "Breeze Blade スタックインストール"

    # Breeze で追加されたマイグレーションを実行
    php artisan migrate --force
fi

# ============================================================
# Phase 6: Blade テンプレートを CDN化（Tailwind/Alpine/ApexCharts）
# ============================================================
log_section "Phase 6: Bladeレイアウトを CDN化"

LAYOUT_FILE="resources/views/layouts/app.blade.php"
GUEST_LAYOUT="resources/views/layouts/guest.blade.php"

# Vite ディレクティブを CDN に置き換える関数
replace_vite_with_cdn() {
    local file="$1"
    if [ ! -f "$file" ]; then
        log_warn "$file が存在しません"
        return
    fi

    cp "$file" "${file}.original"

    # @vite([...]) 行を削除して CDN を挿入
    php -r '
    $f = $argv[1];
    $s = file_get_contents($f);
    $cdn = <<<HTML
        <!-- Tailwind CSS via CDN -->
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        fontFamily: {
                            sans: ["Figtree", "ui-sans-serif", "system-ui", "sans-serif"],
                        }
                    }
                }
            }
        </script>
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Alpine.js -->
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

        <!-- ApexCharts -->
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
HTML;

    // @vite([...]) を CDN に置換
    $s = preg_replace("/@vite\([^)]*\)/", $cdn, $s);
    file_put_contents($f, $s);
    ' "$file"

    log_ok "$file を CDN化"
}

replace_vite_with_cdn "$LAYOUT_FILE"
replace_vite_with_cdn "$GUEST_LAYOUT"

# ============================================================
# Phase 7: 公開ディレクトリ設定
# ============================================================
log_section "Phase 7: 公開ディレクトリ (JRA/) 設定"

# index.php をコピー＆パス書き換え
cp "$LARAVEL_DIR/public/index.php" "$PUBLIC_DIR/index.php"
sed -i "s|__DIR__\.'/\.\.|__DIR__.'/../../laravel|g" "$PUBLIC_DIR/index.php"
log_ok "$PUBLIC_DIR/index.php 配置 + パス書き換え"

# .htaccess
cat > "$PUBLIC_DIR/.htaccess" <<'EOF'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On
    RewriteBase /JRA/

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
EOF
log_ok "$PUBLIC_DIR/.htaccess 配置"

# favicon, robots
[ -f "$LARAVEL_DIR/public/favicon.ico" ] && cp "$LARAVEL_DIR/public/favicon.ico" "$PUBLIC_DIR/" && log_ok "favicon.ico コピー"
[ -f "$LARAVEL_DIR/public/robots.txt" ] && cp "$LARAVEL_DIR/public/robots.txt" "$PUBLIC_DIR/" && log_ok "robots.txt コピー"

# パーミッション
chmod -R 755 "$LARAVEL_DIR/storage"
chmod -R 755 "$LARAVEL_DIR/bootstrap/cache"
log_ok "storage / bootstrap/cache パーミッション設定"

# ============================================================
# Phase 8: 設定キャッシュ最適化
# ============================================================
log_section "Phase 8: キャッシュ"

php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
log_ok "キャッシュクリア完了"

# ============================================================
# Phase 9: 管理者ユーザー作成
# ============================================================
log_section "Phase 9: 管理者ユーザー作成"

php artisan tinker --execute="
\$user = \App\Models\User::where('email', '${ADMIN_EMAIL}')->first();
if (\$user) {
    \$user->update([
        'name' => '${ADMIN_NAME}',
        'password' => \Hash::make('${ADMIN_PASSWORD}'),
    ]);
    echo 'Updated existing user: ${ADMIN_EMAIL}';
} else {
    \App\Models\User::create([
        'name' => '${ADMIN_NAME}',
        'email' => '${ADMIN_EMAIL}',
        'password' => \Hash::make('${ADMIN_PASSWORD}'),
        'email_verified_at' => now(),
    ]);
    echo 'Created user: ${ADMIN_EMAIL}';
}
"
log_ok "管理者ユーザー登録完了"

# ============================================================
# Phase 10: 動作確認
# ============================================================
log_section "Phase 10: 動作確認"

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$APP_URL/" || echo "000")
if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "302" ]; then
    log_ok "$APP_URL/ にアクセス成功 (HTTP $HTTP_CODE)"
else
    log_warn "$APP_URL/ アクセス結果: HTTP $HTTP_CODE （ブラウザで確認してください）"
fi

# ============================================================
# 完了メッセージ
# ============================================================
log_section "🎉 セットアップ完了！"
echo ""
echo "  アプリURL    : $APP_URL/"
echo "  ログインURL  : $APP_URL/login"
echo "  管理者Email  : $ADMIN_EMAIL"
echo "  管理者Pass   : (.env.local に保存済み)"
echo ""
echo "  Laravel本体  : $LARAVEL_DIR"
echo "  公開ディレクトリ: $PUBLIC_DIR"
echo ""
echo "次のステップ:"
echo "  1. ブラウザで $APP_URL/login にアクセス"
echo "  2. 上記のEmail/Password でログイン確認"
echo "  3. 問題なければ scripts/push-to-github.sh で GitHub に push"
echo ""
