<?php

return [
    'attachments_root' => env('BUSINESS_ATTACHMENTS_ROOT', storage_path('app/private/business-attachments')),
    'ocr_url' => env('BUSINESS_OCR_URL'),
    // 本地 Docker 可直接识别；配置 OCR 服务地址时仍优先使用原有引擎。
    'ocr_binary' => env('BUSINESS_OCR_BINARY', 'tesseract'),
    'ocr_languages' => env('BUSINESS_OCR_LANGUAGES', 'eng+chi_sim'),
];
