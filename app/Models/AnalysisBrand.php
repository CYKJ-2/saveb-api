<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 品牌按规范化全名识别，允许不同品牌使用相同来源代号。 */
class AnalysisBrand extends Model
{
    protected $table = 'analysis_brands';

    protected $guarded = [];

    protected $casts = ['aliases' => 'array', 'source_entries' => 'array', 'is_active' => 'boolean'];
}
