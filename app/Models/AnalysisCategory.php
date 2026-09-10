<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 商品品类字典；独立于原订单的销售渠道分类。 */
class AnalysisCategory extends Model
{
    protected $table = 'analysis_categories';

    protected $guarded = [];
}
