<?php

namespace App\Services;

use App\Dao\AttachmentDao;
use App\Models\Attachment;
use App\Models\InvoiceOrder;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Invoice 详情图片：校验当前订单绑定后直接返回图片内容。 */
class InvoiceImageService
{
    public function __construct(
        private AttachmentDao $attachmentDao,
        private AttachmentService $attachmentService,
    ) {
    }

    /** 仅供已鉴权的订单详情调用，列表不携带图片二进制内容。 */
    public function forInvoice(InvoiceOrder $invoice): array
    {
        $ids = $invoice->items->pluck('image_attachment_id')->all();
        $ids[] = $invoice->invoice_screenshot_attachment_id;
        $attachments = $this->attachmentDao->findMany($ids);
        $images = [];
        foreach ($invoice->items as $item) {
            $images[$item->id] = $this->preview(
                $attachments->get($item->image_attachment_id),
                $item->image_attachment_id,
                $invoice->id,
                'invoice_item_image',
            );
        }

        return [
            'screenshot' => $this->preview(
                $attachments->get($invoice->invoice_screenshot_attachment_id),
                $invoice->invoice_screenshot_attachment_id,
                $invoice->id,
                'invoice_screenshot',
            ),
            'items' => $images,
        ];
    }

    /** 缺失图片不能使整张订单无法编辑，也不能回退到其他订单的附件。 */
    private function preview(?Attachment $attachment, ?int $id, int $invoiceId, string $type): array
    {
        $image = ['id' => $id, 'src' => null, 'status' => $id ? 'missing' : 'empty'];
        if (!$attachment) {
            return $image;
        }
        if ($attachment->entity_type !== $type || (int) $attachment->entity_id !== $invoiceId) {
            return array_replace($image, ['status' => 'forbidden']);
        }
        try {
            $path = $this->attachmentService->path($attachment);
            $mime = mime_content_type($path);
            if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                return array_replace($image, ['status' => 'unavailable']);
            }
            $content = file_get_contents($path);
            if ($content === false) {
                return array_replace($image, ['status' => 'unavailable']);
            }

            return array_replace($image, ['src' => 'data:' . $mime . ';base64,' . base64_encode($content), 'status' => 'ready']);
        } catch (HttpExceptionInterface $exception) {
            $status = match ($exception->getStatusCode()) {
                404 => 'missing',
                409 => 'integrity_failed',
                default => 'unavailable',
            };

            return array_replace($image, ['status' => $status]);
        }
    }
}
