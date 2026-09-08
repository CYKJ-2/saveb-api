<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$checks = [
 'invoice' => fn () => app(App\Services\InvoiceService::class)->listing([])['total'],
 'sa_sales' => fn () => app(App\Services\SaSalesService::class)->report(['startDate' => '2026-08-01','endDate' => '2026-08-31'])['metrics'],
 'procurement' => fn () => app(App\Services\ProcurementService::class)->statistics([]),
 'warehouse' => fn () => app(App\Services\WarehouseService::class)->listing(['scope' => 'all'])['total'],
 'influencer' => fn () => count(app(App\Services\InfluencerService::class)->directory()),
 'paypal' => fn () => count(app(App\Services\PaypalService::class)->listing()),
 'operations' => fn () => count(app(App\Services\OperationsService::class)->directory([])),
];
foreach ($checks as $name => $check) {
    $time = microtime(true);
    try {
        $result = $check();
        echo json_encode(['module' => $name,'seconds' => round(microtime(true) - $time, 2),'result' => $result], JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo json_encode(['module' => $name,'error' => $e->getMessage()]) . "\n";
        exit(1);
    }
}
$used = App\Models\InvoiceOrder::pluck('invoice_screenshot_attachment_id')->merge(App\Models\InvoiceItem::pluck('image_attachment_id'))->filter()->flip();
$storage = ['total' => 0,'readable' => 0,'missing' => 0,'invalid' => 0,'referencedMissing' => 0];
foreach (App\Models\Attachment::cursor() as $attachment) {
    $storage['total']++;
    try {
        app(App\Services\AttachmentService::class)->path($attachment);
        $storage['readable']++;
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $storage[$e->getStatusCode() === 404 ? 'missing' : 'invalid']++;
        if ($used->has($attachment->id)) {
            $storage['referencedMissing']++;
        }
    }
}
echo json_encode(['attachments' => $storage]) . "\n";
echo json_encode(['permissions' => App\Models\Permission::where('code', 'like', 'business.%')->selectRaw('type,count(*) as count')->groupBy('type')->pluck('count', 'type')->all()]) . "\n";
