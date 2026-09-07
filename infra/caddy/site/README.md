# infra/caddy/site

`app.n-cards.de`（App Links 域）下的**全部**内容。T-106 交付，ADR-0016 记录取舍。

由 `Caddyfile` 里第二个站点块直接 `file_server` 出去 —— **没有 reverse_proxy**。

## 为什么是静态文件

企业邮件安全网关（Microsoft Defender、Barracuda …）会自动 `GET` 邮件里的每一个
链接做扫描。Magic Link 若是 `GET` 即消费，用户还没点开就已失效（§7.1）。

把落地页放在 `file_server` 上，「GET 不改变状态」就从一条要靠人记住的约定变成了
结构事实：后端在 `/l/` 下**没有任何路由**，
`backend/tests/Api/RouteInventoryTest::testTheBackendServesNothingUnderTheAppLinksPath()`
钉着这一条。真正的消费是 App 发的 `POST /v1/auth/magic/consume`。

## 目录

| 路径 | 谁发出这个链接 | 说明 |
|---|---|---|
| `l/magic/index.html` | T-106，验证码信正文 | `/l/magic/<token>` 全部 rewrite 到这一份；令牌由 `assets/magic.js` 从 `location.pathname` 读出来拼成 `intent://` |
| `l/devices/index.html` | T-104，「新设备登录」提醒信的「这不是我」 | 无令牌，纯说明页 |
| `l/security/index.html` | T-105，refresh 重放的安全提醒信 | 无令牌，纯说明页 |
| `assets/base.css`、`assets/magic.js` | — | 独立文件而不是内联，好让站点块的 CSP 停在 `'self'` 而不是 `'unsafe-inline'` |
| `.well-known/assetlinks.json` | Android 安装时自动拉取 | 仓库里是占位的 `[]`；真值由 Ansible 按环境渲染，见下 |

## assetlinks.json

仓库里这份是 `[]`（合法 JSON，不授权任何 App）。真值由
`infra/ansible/roles/ncards_stack/templates/assetlinks.json.j2` 从两个 group_vars
渲染：`ncards_android_package_name` 与 `ncards_android_cert_fingerprints`。

⚠️ 今天两个环境的指纹都是空的 —— 仓库里还没有 release keystore。
效果是 App Links 验证不通过，点信里的链接落到这里的 Web 落地页而不是直接拉起 App。
**功能不坏，只是少一步。** 填指纹归 T-151 / M4，那时 Android 侧的
`intent-filter` + `autoVerify` 才一起落地；两边缺一边链接都不会拉起。

## 本地

compose 把这个站点块映射在 `http://localhost:8081`
（`CADDY_APP_SITE_ADDRESS` / `APP_SITE_HTTP_PORT`）。

⚠️ `backend/.env` 的 `APP_PUBLIC_BASE_URL` 必须指向同一个地方，否则本地 Mailpit
收到的信里那个链接点开是 404 —— 或者更糟，指向**生产**。

## 上线前提（仓库外）

`app.n-cards.de` / `app.staging.n-cards.de` 的 A 记录必须先存在，Caddy 才签得出
证书。首签前把 `ACME_CA` 指向 Let's Encrypt 的 staging 目录排练，理由与流程见
`infra/caddy/README.md`。
