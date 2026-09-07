# infra/caddy

Caddy 反向代理配置。**T-003 交付最小可用版，T-012 完善。**

[`Caddyfile`](Caddyfile) 一份文件同时服务三个环境，站点地址由
`CADDY_SITE_ADDRESS` 参数化（本地 `http://localhost` 明文；staging / prod 是真实
域名，Caddy 自动申请证书）。

| 已有 | 交付于 |
|---|---|
| `reverse_proxy app:8080` | T-003 ✅ |
| 下面全部安全响应头 | T-003 ✅ |
| 禁 `Server` / `X-Powered-By` / `Via` | T-003 ✅ |
| ACME 账户邮箱与目录（`ACME_EMAIL` / `ACME_CA`） | T-012 ✅ |
| Grafana 的 basic auth 反代 | T-405 ⏳ |
| **App Links 域（`app.n-cards.de`）的第二个站点块** | **T-106 ✅** |

> **静态法律页（§8.6）从 T-012 移出了。** 两个原因：
> ① 这个站点的 `Content-Security-Policy: default-src 'none'` 与 HTML 页面直接冲突 ——
> 页面自己的 CSS 与字体全会被拦；
> ② Impressum 需要真实法律主体信息、Datenschutzerklärung 需要终版子处理者清单，
> 都不是 M0 能定的。
> 它属于 `n-cards.de` 的站点任务（同时要承载 Play 要求的站外
> `https://n-cards.de/delete-account`），**不属于 `api.` 这个子域**。

> **T-106 加了第二个站点块**（`{$CADDY_APP_SITE_ADDRESS}`），服务 App Links 域下的
> Magic Link 落地页与 `assetlinks.json`。它**只 `file_server`，不 `reverse_proxy`** ——
> 邮件安全网关会自动 GET 邮件里的每个链接，让那些 GET 落在静态文件上，
> 「GET 不消费令牌」就是结构保证而不是约定。内容与取舍见
> [`site/README.md`](site/README.md) 与 `docs/adr/0016-magic-link-delivery-and-landing-page.md`。
>
> 它的 CSP 与 API 那个块**不同**（那边 `default-src 'none'`，这边真的有 HTML），
> 这正是 M0 把静态法律页移出 `api.` 子域时说的那个冲突 —— 解法同样是「另开一个站点块」。

`ACME_CA` 排练时可指向 Let's Encrypt 的 staging 目录避开速率限制
（同一域名每周 5 张证书，首签调不通时很容易撞上）。⚠️ 那时签出来的证书**不受公共
信任**，冒烟测试要带 `SMOKE_INSECURE=1`；**验收必须在生产目录、不带那个开关的
情况下跑**。

证书持久化在 `caddy_data` 卷，正常部署 **0 次签发** —— 所以绝不能
`docker compose down -v`（那会把证书一起删掉，重签还可能正好撞上速率限制）。

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
这四条已经写成可执行的断言，跑
[`scripts/ci/smoke-staging.sh`](../../scripts/ci/smoke-staging.sh) 即可
（每次部署后 CI 会自动跑一遍）。

> ⚠️ 手工对拍时注意：HTTP/2 的响应头名在协议层就是**小写**的，`curl -I` 原样输出。
> 冒烟脚本统一转小写再比 —— 别按 `Strict-Transport-Security` 的大小写去 grep。
