<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 供应商字典；不自动合并名称相近但身份未经确认的供应商。 */
class AnalysisSupplier extends Model
{
    protected $table = 'analysis_suppliers';

    protected $guarded = [];

    protected $casts = ['mapping_rules' => 'array'];
}
