<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Session;

use App\Shared\Domain\Identity\Uuid;

/**
 * 设备管理页上的一行（契约里的 `Device`）。
 *
 * 扁平标量而不是 `Device` 实体，理由与 {@see SessionIssued} 逐字相同：
 * deptrac 里 `Identity.Http` 看不到 `Identity.Domain`，把实体塞进来
 * `DeviceController` 就编译不过 —— 而那个约束正好把「控制器不许有业务」
 * 从约定变成了机械强制。
 *
 * ============================================================================
 * ⚠️ 这里**没有** `push_token`，是刻意的
 * ============================================================================
 * FCM 令牌是一个发给第三方（Google Ireland）的标识符，ROPA §8.2 登记的用途
 * 只有「投递推送」。把它回显给客户端不服务于任何一个用例 ——
 * 客户端自己刚上报过它，不需要读回来 —— 却会让它多出现在一处响应体、
 * 一处 HTTP 缓存与任何抓过包的中间层里。
 *
 * `pushTokenUpdatedAt` 则**有**：客户端要靠它判断「我上报的那个还新鲜吗」，
 * 而一个时间戳不泄露令牌本身。
 */
final readonly class DeviceView
{
    /**
     * @param string              $platform           `android`
     * @param bool                $isCurrent          这一行是不是**发起本次请求**的那台设备。
     *                                                由 access token 的 `did` 比对得出，不进库 ——
     *                                                「当前」是请求的属性，不是设备行的属性
     * @param ?\DateTimeImmutable $pushTokenUpdatedAt 从未上报过则 null。
     *                                                格式化成 RFC 3339 是 Http 层的事（§6.1），
     *                                                口径同 {@see SessionIssued::$userCreatedAt}
     */
    public function __construct(
        public Uuid $id,
        public string $platform,
        public ?string $model,
        public ?string $osVersion,
        public ?string $appVersion,
        public bool $isCurrent,
        public \DateTimeImmutable $lastSeenAt,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $pushTokenUpdatedAt,
    ) {
    }
}
