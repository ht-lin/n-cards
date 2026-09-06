<?php

declare(strict_types=1);

namespace App\Shared\Domain\Token;

/**
 * 一枚签好的 access token 与它的剩余寿命 —— `Session` 响应体里的
 * `access_token` + `expires_in` 那一对（`docs/api/openapi.yaml` 的 `Session`）。
 *
 * 两个值捆在一起返回，是为了不让调用方自己算 `expires_in`：
 * 那个数必须与 token 里的 `exp` 同源，分开算迟早会漂
 * （典型的漂法是控制器回填配置里的 900，而签名时用的是别的时钟）。
 */
final readonly class IssuedAccessToken
{
    /**
     * @param string      $token            紧凑序列化的 JWS（`header.payload.signature`）
     * @param int<1, max> $expiresInSeconds §7.1：900
     */
    public function __construct(
        public string $token,
        public int $expiresInSeconds,
    ) {
    }
}
