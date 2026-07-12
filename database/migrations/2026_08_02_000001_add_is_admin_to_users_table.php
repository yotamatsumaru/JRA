<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * セキュリティ強化: 管理者ロールの追加
 *
 * これまで「ログイン済みユーザー = 全員が管理機能(DBビューア/運用ダッシュボード/
 * インポート/レース・馬の書き込み)にフルアクセス可能」という設計だったが、
 * 会員登録は誰でも可能なため、登録した一般ユーザーが管理機能に触れてしまう
 * リスクがあった。
 *
 * 対応:
 *  - users.is_admin (bool, default false) を追加
 *  - 既存ユーザー(このマイグレーション実行時点で既に登録済みの全ユーザー)は
 *    運用に支障が出ないよう is_admin=true に一括移行 (後方互換性維持)
 *  - 以降の新規登録ユーザーは is_admin=false がデフォルト
 *    (管理者に格上げする場合は DB を直接操作するか、既存管理者が今後追加する
 *     管理画面から昇格させる運用を想定)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        // 既存ユーザーは後方互換性のため管理者として移行
        DB::table('users')->update(['is_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
