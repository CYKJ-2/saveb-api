<?php

namespace App\Services;

use App\Dao\AttachmentDao;
use App\Models\Attachment;
use App\Models\InvoiceOrder;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Invoice 详情图片：校验当前订单绑定后直接返回图片内容。 */
class InvoiceImageService
{
    /**
     * 注入 Invoice 图片处理所需的依赖。
     *
     * @param  AttachmentDao  $attachmentDao  附件数据访问对象
     * @param  AttachmentService  $attachmentService  附件业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(
        private AttachmentDao $attachmentDao,
        private AttachmentService $attachmentService,
    ) {
    }

    /**
     * 仅供已鉴权的订单详情调用，列表不携带图片二进制内容。
     *
     * @param  InvoiceOrder  $invoice  Invoice 订单模型
     * @return array 截图和各商品附件的预览映射；缺失图片保留可识别的空预览
     * @see AttachmentDao::findMany()
     */
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

    /**
     * 缺失图片不能使整张订单无法编辑，也不能回退到其他订单的附件。
     *
     * @param  Attachment|null  $attachment  附件模型；null 表示不存在或尚未创建
     * @param  int|null  $id  Invoice 图片记录主键 ID
     * @param  int  $invoiceId  目标 Invoice 订单主键 ID
     * @param  string  $type  附件用途，取值须与绑定时的 entity_type 一致
     * @return array 单张图片的内联预览及可用状态
     * @see AttachmentService::path()
     */
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
