# infra/caddy

Caddy 反向代理配置。**由 T-012 交付**，当前目录为空。

## 必须具备

- 自动 TLS（Let's Encrypt）
- 安全响应头：
  - `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: no-referrer`
  - `X-Frame-Options: DENY`
  - `Content-Security-Policy: default-src 'none'`
- 禁用 `X-Powered-By` / `Server` 版本回显

验收：`curl -I https://api.staging.ncards.de` 能看到全部安全头，且无版本回显。
