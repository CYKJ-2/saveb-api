<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 当前附件按上传用户、Invoice 绑定独立管理。同一图片可以有多条记录；
        // SHA256 继续校验文件完整性，但不能用内容哈希合并不同用户的访问权限。
        $constraints = DB::select(<<<'SQL'
            SELECT conname
            FROM pg_constraint
            WHERE conrelid = 'attachments'::regclass
              AND contype = 'u'
              AND conkey = ARRAY[
                  (SELECT attnum FROM pg_attribute
                   WHERE attrelid = 'attachments'::regclass AND attname = 'sha256')
              ]::smallint[]
            SQL);

        foreach ($constraints as $constraint) {
            $name = str_replace('"', '""', $constraint->conname);
            DB::statement('ALTER TABLE attachments DROP CONSTRAINT "' . $name . '"');
        }
    }

    public function down(): void
    {
        // 已有重复内容附件时不能重新加唯一约束，保留合法的上传记录。
    }
};
