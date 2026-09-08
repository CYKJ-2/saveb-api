<?php

namespace App\Exceptions;

use App\Common\RespDef;
use Exception;
use Throwable;

/**
 * 业务异常类：承载 API 错误码 + HTTP 状态码。
 *
 * 用法：
 *   throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
 *   throw SystemException::from(RespDef::CODE_INVALID_CREDENTIALS);
 *
 * 由 bootstrap/app.php → Response::error() 统一渲染。
 */
class SystemException extends Exception
{
    /** @var int 业务码（与 RespDef::CODE_* 对应） */
    private int $apiCode;

    /** @var int HTTP 状态码 */
    private int $httpStatus;

    /**
     * @param  int         $apiCode    业务错误码
     * @param  string      $message    错误消息；为空时自动按 RespDef 推导
     * @param  int         $httpStatus HTTP 状态码
     * @param  Throwable|null $previous 上游异常
     */
    public function __construct(
        int $apiCode,
        string $message = '',
        int $httpStatus = 200,
        ?Throwable $previous = null,
    ) {
        $this->apiCode   = $apiCode;
        $this->httpStatus = $httpStatus;

        $msg = ($message !== '') ? $message : $this->fallbackMessage();
        parent::__construct($msg, $apiCode, $previous);
    }

    /**
     * 获取业务错误码。
     *
     * @return int  RespDef::CODE_*
     */
    public function getApiCode(): int
    {
        return $this->apiCode;
    }

    /**
     * 获取 HTTP 状态码。
     *
     * @return int  HTTP 状态码
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * 快捷构造：自动按 RespDef 取默认消息与 HTTP 状态。
     *
     * @param  int   $apiCode     业务错误码
     * @param  int|null $httpStatus 自定义 HTTP 状态；为空时由 code 推导
     * @return self
     */
    public static function from(int $apiCode, ?int $httpStatus = null): self
    {
        $status = $httpStatus ?? self::defaultHttpStatus($apiCode);

        return new self($apiCode, '', $status);
    }

    /**
     * 业务错误码 → 默认 HTTP 状态码的映射。
     *
     * @param  int  $code  RespDef::CODE_*
     * @return int         HTTP 状态码
     */
    public static function defaultHttpStatus(int $code): int
    {
        return match (true) {
            $code === RespDef::CODE_SUCCESS => 200,
            $code === RespDef::CODE_NOT_FOUND,
            $code === RespDef::CODE_USER_NOT_FOUND,
            $code === RespDef::CODE_DATA_NOT_FOUND,
            $code === RespDef::CODE_ROLE_NOT_FOUND,
            $code === RespDef::CODE_ORDER_NOT_FOUND,
            $code === RespDef::CODE_PERMISSION_NOT_FOUND => 404,
            $code === RespDef::CODE_INVALID_CREDENTIALS,
            $code === RespDef::CODE_TOKEN_INVALID,
            $code === RespDef::CODE_TOKEN_EXPIRED => 401,
            $code === RespDef::CODE_FORBIDDEN,
            $code === RespDef::CODE_ROLE_NOT_ALLOWED,
            $code === RespDef::CODE_PERMISSION_DENIED => 403,
            $code === RespDef::CODE_VALIDATION_FAIL,
            $code === RespDef::CODE_MISSING_PARAMS,
            $code === RespDef::CODE_INVALID_PARAMS,
            $code === RespDef::CODE_ROLE_ALREADY_EXISTS,
            $code === RespDef::CODE_PERMISSION_ALREADY_EXISTS,
            $code === RespDef::CODE_ORDER_ALREADY_EXISTS,
            $code === RespDef::CODE_ROLE_HAS_USERS,
            $code === RespDef::CODE_SYSTEM_ROLE_PROTECTED => 422,
            default => 200,
        };
    }

    /**
     * 按 apiCode 从 RespDef 推导默认消息。
     *
     * @return string  默认错误消息
     */
    private function fallbackMessage(): string
    {
        $r = new \ReflectionClass(RespDef::class);
        $constName = $this->codeToMsgConst();
        if ($r->hasConstant($constName)) {
            return (string) $r->getConstant($constName);
        }

        return RespDef::MSG_SYSTEM_ERROR;
    }

    /**
     * 由 apiCode 反查 RespDef 中对应的 MSG_* 常量名。
     *
     * @return string  MSG_* 常量名
     */
    private function codeToMsgConst(): string
    {
        $r = new \ReflectionClass(RespDef::class);
        foreach ($r->getConstants() as $name => $value) {
            if (str_starts_with($name, 'CODE_') && $value === $this->apiCode) {
                return str_replace('CODE_', 'MSG_', $name);
            }
        }

        return 'MSG_SYSTEM_ERROR';
    }
}
