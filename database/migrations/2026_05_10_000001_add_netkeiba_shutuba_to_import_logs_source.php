<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * import_logs.source の ENUM に 'netkeiba_shutuba' を追加
 *
 * 元定義: enum('manual', 'csv', 'netkeiba', 'image')
 * 追加後: enum('manual', 'csv', 'netkeiba', 'netkeiba_shutuba', 'image')
 *
 * 出馬表取込(netkeiba:shutuba / netkeiba:shutuba-date)が ImportLog::create で
 * source='netkeiba_shutuba' を入れた際に "Data truncated for column 'source'"
 * になるのを防ぐ。
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL/MariaDB 専用 ENUM 拡張
        DB::statement("ALTER TABLE `import_logs` MODIFY COLUMN `source` "
            . "ENUM('manual','csv','netkeiba','netkeiba_shutuba','image') "
            . "NOT NULL COMMENT 'インポート元'");
    }

    public function down(): void
    {
        // 'netkeiba_shutuba' の行が残っていると DOWN は失敗するので、念のため変換しておく
        DB::statement("UPDATE `import_logs` SET `source`='netkeiba' WHERE `source`='netkeiba_shutuba'");
        DB::statement("ALTER TABLE `import_logs` MODIFY COLUMN `source` "
            . "ENUM('manual','csv','netkeiba','image') "
            . "NOT NULL COMMENT 'インポート元'");
    }
};
