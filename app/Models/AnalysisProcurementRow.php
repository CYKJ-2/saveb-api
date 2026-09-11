<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 采购表的一行商品记录；金额保留十进制，缺失金额保持 null。 */
class AnalysisProcurementRow extends Model
{
    protected $table = 'analysis_procurement_rows';

    protected $guarded = [];

    protected $casts = [
        'raw' => 'array',
        'classification_evidence' => 'array',
        'issues' => 'array',
        'actual_price' => 'decimal:2',
        'analysis_amount' => 'decimal:2',
        'quantity' => 'decimal:4',
        'is_cancelled' => 'boolean',
        'is_current' => 'boolean',
        'is_eligible' => 'boolean',
        'supplier_quote' => 'decimal:2',
    ];
}
