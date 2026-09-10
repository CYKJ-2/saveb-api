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
    /**
     * 注入 附件处理所需的依赖。
     *
     * @param  AttachmentService  $attachmentService  附件业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private AttachmentService $attachmentService)
    {
    }

    /**
     * 保存上传文件。
     *
     * 请求字段（校验规则）：
     * - file：'required|file|mimes:jpg,jpeg,png,webp|max:25600'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为附件的业务结果
     * @see AttachmentService::upload()
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp|max:25600']);

        return AppResponse::success($this->attachmentService->upload($request->file('file'), (int) $request->attributes->get('auth_user')->id));
    }

    /**
     * 读取附件详情或指定模块数据。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  int  $id  附件记录主键 ID
     * @return BinaryFileResponse 经过访问校验的附件文件响应
     * @see AttachmentService::file()
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
