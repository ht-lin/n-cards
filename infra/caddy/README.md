# infra/caddy

Caddy 反向代理配置。**T-003 交付最小可用版，T-012 完善。**

[`Caddyfile`](Caddyfile) 一份文件同时服务三个环境，站点地址由
`CADDY_SITE_ADDRESS` 参数化（本地 `http://localhost` 明文；staging / prod 是真实
域名，Caddy 自动申请证书）。

| 已有（T-003） | 待补（T-012） |
|---|---|
| `reverse_proxy app:8080` | 静态法律页（Impressum / 数据保护声明，§8.6） |
| 下面全部安全响应头 | 真实域名与 ACME 账户配置 |
| 禁 `Server` / `X-Powered-By` / `Via` | Grafana 的 basic auth 反代（T-405） |

> HSTS 只对 `{scheme} == "https"` 发。本地是明文 `http://localhost`，发了会把
> 开发者的浏览器对 localhost 永久锁进 HTTPS —— 那是个很难排查的坑。

## 必须具备

- 自动 TLS（Let's Encrypt）
- 安全响应头：
  - `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: no-referrer`
  - `X-Frame-Options: DENY`
  - `Content-Security-Policy: default-src 'none'`
- 禁用 `X-Powered-By` / `Server` 版本回显

验收：`curl -I https://api.staging.n-cards.de` 能看到全部安全头，且无版本回显。
