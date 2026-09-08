<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 附件接口：负责参数校验、调用服务和封装响应。
 */
class AttachmentController
{
    public function __construct(private AttachmentService $attachmentService)
    {
    }

    /**
     * 保存上传文件。
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp|max:25600']);

        return AppResponse::success($this->attachmentService->upload($request->file('file'), (int) $request->attributes->get('auth_user')->id));
    }

    /**
     * 读取详情。
     */
    public function show(Request $request, int $id): BinaryFileResponse
    {
        $codes = $request->attributes->get('auth_permission_codes', []);
        $actorId = (int) $request->attributes->get('auth_user')->id;
        $canReadBoundAttachment = (bool) array_intersect(['*', 'business.invoice.list'], $codes);
        [$attachment, $path] = $this->attachmentService->file($id, $actorId, $canReadBoundAttachment);

        return response()->file($path, [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
