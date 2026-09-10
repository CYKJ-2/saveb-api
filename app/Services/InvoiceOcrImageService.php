<?php

namespace App\Services;

/** 为本地 OCR 放大小字号截图；附件原文件和校验值保持不变。 */
class InvoiceOcrImageService
{
    /**
     * 在内存预算内放大小字号截图并铺白底，供本地 OCR 识别。
     *
     * @param  string  $path  本地文件绝对路径
     * @return string 放大后临时图片路径；不适合预处理时返回原路径，处理异常向上抛出
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     */
    public function prepare(string $path): string
    {
        $size = @getimagesize($path);
        if (!$size || !extension_loaded('gd')) {
            return $path;
        }
        [$width, $height] = $size;
        $pixels = $width * $height;
        // 控制解码及放大后的内存，长截图或已经足够清晰的图片直接交给 OCR。
        if ($pixels > 12000000 || $width >= 2400) {
            return $path;
        }
        $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
        $availableMemory = $memoryLimit > 0 ? $memoryLimit - memory_get_usage(true) : 128 * 1024 * 1024;
        // GD 的 Bicubic 会额外分配中间图；预留框架内存，防止清晰大图放大后触发 PHP OOM。
        $maximumPixels = min(7000000, max(0, $availableMemory - 8 * 1024 * 1024 - $pixels * 8) / 10);
        $scale = min(3, ceil(2400 / $width), sqrt($maximumPixels / $pixels));
        if ($scale < 1.5) {
            return $path;
        }
        $source = match ($size[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
        if (!$source) {
            return $path;
        }
        $targetWidth = (int) round($width * $scale);
        $targetHeight = (int) round($height * $scale);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        // Bicubic 保留细字与小数点；普通重采样容易把“1 x”读成“i x”。
        $enlarged = imagescale($canvas, $targetWidth, $targetHeight, IMG_BICUBIC);
        imagedestroy($canvas);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'invoice-ocr-');
        try {
            abort_unless($enlarged && $temporaryPath, 503, 'OCR 图片处理失败，请重新上传');
            abort_unless(imagepng($enlarged, $temporaryPath), 503, 'OCR 图片处理失败，请重新上传');

            return $temporaryPath;
        } catch (\Throwable $exception) {
            if ($temporaryPath && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
            throw $exception;
        } finally {
            imagedestroy($source);
            if ($enlarged) {
                imagedestroy($enlarged);
            }
        }
    }
}
