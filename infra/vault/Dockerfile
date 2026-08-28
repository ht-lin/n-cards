# vault-init 的镜像（T-005）——  只为了 bootstrap.sh 的三个依赖而存在。
#
# ============================================================================
# 为什么需要自建镜像
# ============================================================================
# bootstrap.sh 要 curl、jq、openssl 三样（理由见脚本头部）。而：
#   - `alpine:3` 与 `hashicorp/vault:1.18` 里**三个都没有**，只有 busybox 的 wget
#   - 没法在容器启动时 `apk add` —— compose 的 `backing` 网络是 `internal: true`
#     （§7.4：连出站都没有），装包会直接 DNS 失败
#
# 于是只剩「构建期装好」这一条路。构建走 Docker 的默认网络（有出站），
# 运行时容器仍然只连 `backing`（无出站）—— §7.4 的隔离一点没松。
# 第一次 `docker compose up` 会构建一次，之后走缓存。
#
# ⚠️ 这个镜像**只在本地与 staging 起**。生产的 vault-init 被
# docker-compose.prod.yml 用 `profiles: ["disabled"]` 关掉了，
# 那里的初始化是 unseal 之后的人工步骤（docs/runbooks/vault-unseal.md）。
#
# ============================================================================
# 为什么不把这三样装进 app 镜像
# ============================================================================
# 想过复用 backend 的镜像（它已经装了 curl，且在同一个网络里）。不行：
# 那会把 jq 与 openssl 带进**生产**运行时，而 §7.4 的容器加固方向正相反 ——
# 一次 RCE 之后，攻击者手边多一套顺手的工具链，损失是实打实的。
# 一个只在 dev/staging 起、跑完就退出的一次性容器是更小的暴露面。

FROM alpine:3.21

# 版本不钉死到补丁号：这是一次性的初始化工具容器，不处理任何用户数据，
# 拿最新的安全补丁比拿可复现的构建更划算。
# （生产**运行时**的镜像是另一回事，那些在 docker-compose.prod.yml 里都钉到了补丁版本。）
RUN apk add --no-cache curl jq openssl

# 脚本与 policies/ 由 compose 以 :ro 绑定挂载进来（见 docker-compose.base.yml），
# 不烤进镜像 —— 改一行策略不用重建镜像。
WORKDIR /vault/bootstrap

# 非 root：这个容器只发 HTTP 请求，不需要任何特权。
USER nobody

ENTRYPOINT ["sh", "/vault/bootstrap/bootstrap.sh"]
