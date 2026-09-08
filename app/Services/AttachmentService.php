<?php

namespace App\Services;

use App\Dao\AttachmentDao;
use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * 附件服务：处理业务规则、统计口径和事务。
 */
class AttachmentService
{
    public function __construct(private AttachmentDao $attachmentDao)
    {
    }

    /**
     * 去重并按顺序锁定待绑定附件，交由 Invoice 事务管理锁生命周期。
     */
    public function lockBindings(array $ids): void
    {
        $this->attachmentDao->lockMany(array_unique(array_filter($ids)));
    }

    /**
     * 保存上传文件。
     */
    public function upload(UploadedFile $file, int $actor): array
    {
        $info = getimagesize($file->getRealPath());
        abort_unless($info && $info[0] > 0 && $info[1] > 0 && $info[0] * $info[1] <= 40000000, 422, '图片尺寸无效或超过 4000 万像素');
        $root = config('business.attachments_root');
        $relative = 'api/' . Str::uuid() . '.' . $file->extension();
        if (!is_dir($root . '/api')) {
            mkdir($root . '/api', 0775, true);
        }
        $file->move($root . '/api', basename($relative));
        chmod($root . '/' . $relative, 0640);
        try {
            $attachment = $this->attachmentDao->create([
                'entity_type' => 'invoice_upload',
                'owner_user_id' => $actor,
                'file_path' => $relative,
                'mime' => $info['mime'],
                'size_bytes' => filesize($root . '/' . $relative),
                'sha256' => hash_file('sha256', $root . '/' . $relative),
                'entity_uuid' => (string) Str::uuid(),
            ]);
        } catch (\Throwable $exception) {
            unlink($root . '/' . $relative);
            throw $exception;
        }

        return [
            'id' => $attachment->id,
            'mime' => $attachment->mime,
        ];
    }

    /**
     * 检查附件归属并获取文件。
     */
    public function file(
        int $id,
        int $actor,
        bool $readBound = false,
    ): array {
        $attachment = $this->attachmentDao->find($id);
        abort_unless((int) $attachment->owner_user_id === $actor || $readBound && $this->attachmentDao->bound($attachment), 403);

        return [$attachment, $this->path($attachment)];
    }

    /**
     * 校验并解析附件路径。
     */
    public function path(Attachment $attachment): string
    {
        $root = realpath(config('business.attachments_root'));
        abort_unless($root, 503, '附件存储未就绪');
        $relative = preg_replace('#^/data/attachments/#', '', $attachment->file_path);
        $path = realpath($root . '/' . $relative);
        abort_unless($path && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path), 404, '附件文件不存在');
        abort_unless(hash_equals($attachment->sha256, hash_file('sha256', $path)), 409, '附件校验失败');

        return $path;
    }

    /**
     * 校验附件归属、用途及文件摘要，拒绝跨用户绑定。
     */
    public function validateBinding(
        int $id,
        int $actor,
        ?int $invoice,
        string $type,
    ): Attachment {
        $attachment = $this->attachmentDao->find($id);
        $alreadyBound = $invoice && (int) $attachment->entity_id === $invoice && $attachment->entity_type === $type && $this->attachmentDao->bound($attachment);
        abort_unless(
            $alreadyBound || !$attachment->entity_id && (int) $attachment->owner_user_id === $actor,
            403,
            '附件不属于当前用户或当前 Invoice',
        );
        $this->path($attachment);

        return $attachment;
    }

    /**
     * 将已验证的附件绑定到 Invoice 或商品明细。
     */
    public function bind(
        Attachment $attachment,
        string $type,
        int $invoice,
    ): void {
        $this->attachmentDao->bind($attachment, $type, $invoice);
    }
}
