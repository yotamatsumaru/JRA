#!/bin/bash
# ============================================================
# JRA App GitHub Push スクリプト (Personal Access Token方式)
# ============================================================
# 実行方法:
#   cd ~/xs524093.xsrv.jp/laravel/
#   bash scripts/push-to-github.sh
# 前提:
#   - scripts/.env.local に GITHUB_USERNAME, GITHUB_REPO, GITHUB_TOKEN が設定済み
#   - https://github.com/yotamatsumaru/JRA が空のリポジトリとして存在
# ============================================================

set -e

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

LARAVEL_DIR="$HOME/xs524093.xsrv.jp/laravel"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_LOCAL="$SCRIPT_DIR/.env.local"

# ============================================================
# 前提チェック
# ============================================================
log_section "前提チェック"

if [ ! -f "$ENV_LOCAL" ]; then
    log_error ".env.local が見つかりません: $ENV_LOCAL"
    exit 1
fi

set -a
source "$ENV_LOCAL"
set +a

REQUIRED=(GITHUB_USERNAME GITHUB_REPO GITHUB_TOKEN)
for v in "${REQUIRED[@]}"; do
    if [ -z "${!v}" ]; then
        log_error ".env.local の $v が空です"
        exit 1
    fi
done
log_ok "GitHub 認証情報 OK (User: $GITHUB_USERNAME, Repo: $GITHUB_REPO)"

cd "$LARAVEL_DIR"

# ============================================================
# Git ユーザー設定
# ============================================================
log_section "Git ユーザー設定"

CURRENT_NAME=$(git config user.name 2>/dev/null || echo "")
CURRENT_EMAIL=$(git config user.email 2>/dev/null || echo "")

if [ -z "$CURRENT_NAME" ] || [ -z "$CURRENT_EMAIL" ]; then
    git config --global user.name "${ADMIN_NAME:-Yota Matsumaru}"
    git config --global user.email "${ADMIN_EMAIL:-noreply@example.com}"
    log_ok "Git ユーザー設定: ${ADMIN_NAME} <${ADMIN_EMAIL}>"
else
    log_ok "Git ユーザー: $CURRENT_NAME <$CURRENT_EMAIL>"
fi

# ============================================================
# .gitignore 整備
# ============================================================
log_section ".gitignore 整備"

cat > .gitignore <<'EOF'
# Laravel 標準
/node_modules
/public/build
/public/hot
/public/storage
/storage/*.key
/storage/pail
/vendor
.env
.env.backup
.env.backup.*
.env.production
.phpactor.json
.phpunit.result.cache
Homestead.json
Homestead.yaml
auth.json
npm-debug.log
yarn-error.log
/.fleet
/.idea
/.nova
/.vscode
/.zed

# このプロジェクト固有
scripts/.env.local
*.log
.DS_Store
Thumbs.db
storage/logs/*.log
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/testing/*
storage/framework/views/*
!storage/framework/cache/.gitignore
!storage/framework/sessions/.gitignore
!storage/framework/testing/.gitignore
!storage/framework/views/.gitignore
EOF

log_ok ".gitignore 整備完了"

# ============================================================
# Git 初期化（必要な場合）
# ============================================================
log_section "Git 初期化"

if [ ! -d ".git" ]; then
    git init
    git branch -M main
    log_ok "git init 実行 (ブランチ: main)"
else
    # main ブランチに統一
    CURRENT_BRANCH=$(git branch --show-current 2>/dev/null || echo "")
    if [ "$CURRENT_BRANCH" != "main" ]; then
        git branch -M main 2>/dev/null || true
    fi
    log_ok "Git 既に初期化済み (ブランチ: main)"
fi

# ============================================================
# リモートリポジトリ設定（PATを使用）
# ============================================================
log_section "リモートリポジトリ設定"

REMOTE_URL="https://${GITHUB_USERNAME}:${GITHUB_TOKEN}@github.com/${GITHUB_USERNAME}/${GITHUB_REPO}.git"

if git remote get-url origin &>/dev/null; then
    git remote set-url origin "$REMOTE_URL"
    log_ok "リモート origin を更新"
else
    git remote add origin "$REMOTE_URL"
    log_ok "リモート origin 追加"
fi

# ============================================================
# コミット & プッシュ
# ============================================================
log_section "コミット作成"

git add -A

if git diff --cached --quiet; then
    log_warn "変更がありません。コミットをスキップします。"
else
    COMMIT_MSG="${1:-Initial commit: Laravel 11 + Breeze (Blade) + Tailwind/Alpine/ApexCharts (CDN)}"
    git commit -m "$COMMIT_MSG"
    log_ok "コミット作成: $COMMIT_MSG"
fi

# ============================================================
# Push
# ============================================================
log_section "GitHub へ Push"

if git push -u origin main 2>&1; then
    log_ok "Push 成功！"
else
    log_warn "通常 push が失敗。force push を試します..."
    git push -u origin main --force
    log_ok "Force push 成功"
fi

# ============================================================
# セキュリティ: リモートURLからトークンを除去
# ============================================================
log_section "セキュリティ後処理"

git remote set-url origin "https://github.com/${GITHUB_USERNAME}/${GITHUB_REPO}.git"
log_ok "リモートURLからトークンを除去（セキュリティ対策）"

# ============================================================
# 完了
# ============================================================
log_section "🎉 GitHub 連携完了！"
echo ""
echo "  リポジトリ: https://github.com/${GITHUB_USERNAME}/${GITHUB_REPO}"
echo ""
echo "次回以降の push:"
echo "  bash scripts/push-to-github.sh \"コミットメッセージ\""
echo ""
echo "（実行のたびに .env.local からトークンを読み込んで認証します）"
echo ""
