<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 只读来源文件的导入批次；新批次完整提交后才切换为当前版本。 */
class AnalysisImport extends Model
{
    protected $table = 'analysis_imports';

    protected $guarded = [];

    protected $casts = ['summary' => 'array', 'is_active' => 'boolean'];
}
