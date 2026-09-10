<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 来源表中的供应商、品牌与品类对应关系及原始位置。 */
class AnalysisSupplierRule extends Model
{
    protected $table = 'analysis_supplier_rules';

    protected $guarded = [];

    protected $casts = ['raw' => 'array'];
}
