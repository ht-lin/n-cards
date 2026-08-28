<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Client\ClientVersion;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * 让任何模块的控制器直接 type-hint `ClientVersion $client` 拿到已解析的客户端版本。
 *
 * ```php
 * #[Route('/v1/cards', methods: ['GET'])]
 * public function list(ClientVersion $client): JsonResponse { ... }
 * ```
 *
 * 二十行代码，省掉七个模块各自重解一遍 header —— 而每一份重解都是一次
 * 「正则写得跟 §6.1 略有出入」的机会。解析只发生在 {@see ClientVersionListener}，
 * 这里只是把结果取出来。
 *
 * 非 `/v1` 端点上没有这个属性（豁免的直接后果），此时解析器不产出任何值，
 * Symfony 会照常报「参数无法解析」—— 那确实是接线错误：一个需要客户端版本的
 * 控制器不该被路由到 `/v1` 之外。
 */
final readonly class ClientVersionValueResolver implements ValueResolverInterface
{
    /**
     * @return iterable<ClientVersion>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (ClientVersion::class !== $argument->getType()) {
            return [];
        }

        $client = ClientVersionListener::readFrom($request);

        return null === $client ? [] : [$client];
    }
}
