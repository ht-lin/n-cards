<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `framework.trusted_proxies` 的回归测试。
 *
 * ============================================================================
 * 这修的是一个从 T-003 起就存在、但要到 T-006 才会显形的隐患
 * ============================================================================
 * `infra/caddy/Caddyfile` 发 `X-Forwarded-Proto`，Caddy 还会自动加 `X-Forwarded-For`，
 * 但**没有 trusted_proxies 时 Symfony 两个都忽略**：
 *   - `Request::getClientIp()` 对每个请求都返回 Caddy 容器的 IP
 *   - `Request::isSecure()` 在 TLS 后面恒为 false
 *
 * T-004 立刻受影响：幂等键在没有认证用户时按 IP 分作用域，所有匿名客户端会挤进
 * 同一个桶。**T-006 则是灾难性的** —— §7.5 的 `POST /auth/otp/request` IP 20/h 与
 * `GET /v1/users/lookup` IP 100/h 会把全世界算作一个 IP，等于对登录端点的拒绝服务，
 * 而且看起来完全像是「限流正常生效了」。
 *
 * 这个 bug 没有任何症状可以自己暴露出来，只能靠这条测试守着。
 */
#[CoversNothing]
final class TrustedProxyTest extends WebTestCase
{
    private const CLIENT = 'android/1.4.0 (26)';

    /**
     * ⚠️ 这里**不能**用 RFC 5737 的文档网段（192.0.2.0/24、198.51.100.0/24、
     * 203.0.113.0/24）—— 那是写这类测试时的第一直觉，但 Symfony 的
     * `private_ranges` 关键字展开成 `IpUtils::PRIVATE_SUBNETS`，**里面就包含这三段**。
     * 用它们当「外部客户端」，会被当成受信代理，测试于是测了个反。
     *
     * 所以取一个真正的公网地址。
     */
    private const REAL_CLIENT_IP = '93.184.216.34';

    /** 同样必须是真正的公网地址，理由同上。 */
    private const UNTRUSTED_SPOOFER_IP = '8.8.8.8';

    public function testClientIpComesFromXForwardedFor(): void
    {
        $client = self::createClient();

        $client->request('GET', '/v1/_probe/echo', server: [
            'HTTP_X_CLIENT' => self::CLIENT,
            // REMOTE_ADDR 模拟 Caddy 容器（在 private_ranges 内，因此被信任）
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => self::REAL_CLIENT_IP,
        ]);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            self::REAL_CLIENT_IP,
            $body['client_ip'],
            'getClientIp() 返回的是代理的 IP 而不是真实客户端 —— '
            .'framework.yaml 的 trusted_proxies 没生效。'
            .'这会让 T-006 的 §7.5 IP 限流把全世界当成一个 IP。',
        );
    }

    public function testProtocolComesFromXForwardedProto(): void
    {
        $client = self::createClient();

        $client->request('GET', '/v1/_probe/echo', server: [
            'HTTP_X_CLIENT' => self::CLIENT,
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue(
            $body['is_secure'],
            'isSecure() 在 X-Forwarded-Proto: https 之后仍为 false —— '
            .'将来生成绝对 URL（邮件里的 Magic Link，§7.1）会拼出 http:// 链接。',
        );
    }

    /**
     * ⚠️⚠️ 反面：X-Forwarded-Host **绝不**可信，哪怕它来自受信代理。
     *
     * 这条和上面两条的方向正好相反，理由在 Caddy 的行为里：`reverse_proxy` 对
     * X-Forwarded-For 是**追加**、对 X-Forwarded-Proto 被 Caddyfile 的 header_up
     * **显式覆盖**，唯独 X-Forwarded-Host 是「客户端没发才补」。而
     * trusted_proxies: private_ranges 让 Caddy 容器受信 —— 于是把这个 header 加进
     * trusted_headers，等于让任意外网客户端直接控制 Request::getHost()。
     *
     * 后果按时间排：现在该值被回显进框架 404 的 detail；T-006 之后，§7.1 邮件里的
     * Magic Link 会指向攻击者的域名 —— 而绝对 URL 正是本文件上面那条
     * testProtocolComesFromXForwardedProto 所守护的东西。
     *
     * 修法是不信任它（现状）；真要按 Host 分支，得先配 framework.trusted_hosts。
     */
    public function testForwardedHostIsNotTrusted(): void
    {
        $client = self::createClient();

        $client->request('GET', '/v1/_probe/echo', server: [
            'HTTP_X_CLIENT' => self::CLIENT,
            // REMOTE_ADDR 是**受信**的代理 —— 这正是这条测试的要害：
            // 即便转发链本身可信，Host 这一项依然由外部客户端说了算。
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'api.n-cards.de',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
        ]);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            'api.n-cards.de',
            $body['host'],
            'X-Forwarded-Host 被信任了 —— 攻击者可以控制 Request::getHost()，'
            .'进而控制 §7.1 Magic Link 邮件里的绝对 URL。'
            .'把 x-forwarded-host 从 framework.yaml 的 trusted_headers 里去掉。',
        );
    }

    /**
     * 反面：**不**受信的来源发的 X-Forwarded-For 必须被忽略。
     *
     * 否则任何人都能伪造自己的 IP，把 §7.5 的按 IP 限流整个绕过去。
     */
    public function testForwardedHeadersFromUntrustedSourcesAreIgnored(): void
    {
        $client = self::createClient();

        $client->request('GET', '/v1/_probe/echo', server: [
            'HTTP_X_CLIENT' => self::CLIENT,
            'REMOTE_ADDR' => self::UNTRUSTED_SPOOFER_IP,
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
        ]);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            self::UNTRUSTED_SPOOFER_IP,
            $body['client_ip'],
            '来自不受信来源的 X-Forwarded-For 必须被忽略，否则按 IP 的限流可以被随意伪造绕过',
        );
    }
}
