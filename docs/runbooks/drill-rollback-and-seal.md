# Runbook：回滚演练与封印演练

> ✅ **staging 上已按本手册执行过一次（2026-09-05），结果回填在
> [`docs/tasks/M0.md`](../tasks/M0.md) 的「实测数字 · 演练」表。** 本文继续有效 ——
> 它同时是生产环境上线前要再走一遍的手册，以及下一次演练的模板。
> ⚠️ 那一次只落下了一个数字（封印后 `vault status` rc=2），耗时全丢了：
> **下次先把文末的记录表打开，再动手。**
>
> **交付**：T-012 的两个未完成项（见 [`docs/tasks/M0.md`](../tasks/M0.md) 的实测数字表）。
> **相关**：[`deploy-and-rollback.md`](deploy-and-rollback.md)、[`vault-unseal.md`](vault-unseal.md)、
> [ADR-0004](../adr/0004-manual-vault-unseal.md)、[ADR-0010](../adr/0010-rollback-image-tag-only.md)、§13.8

这不是故障处置手册，是**主动演练**手册 —— §13.8 的「需要运维介入的事必须演练过
才算交付」。三段演练加起来约 **1.5–2 小时**，其中演练 0 是免费的（下一次正常
合入自动发生），演练 1 约 20 分钟，演练 2 约 40 分钟。

**在 staging 上做，只在 staging 上做。** 现在是成本最低的窗口：staging 上只有
合成数据与一条迁移，出任何岔子最坏是重建环境。

---

## 这三段分别要证伪什么

已经验过的东西不必再验。下表只列**从未在真机上执行过**的代码路径 ——
本地那些 `--syntax-check`、`compose config`、「健康判定 6 种输入」验的是逻辑，
不是这台机器。

| 演练 | 覆盖的路径 | 不做的话，第一次执行是什么时候 |
|---|---|---|
| **0**（免费） | `snapshot.yml` 的 `pg_dump` 分支：`set -o pipefail`、`creates:`、非空断言、保留切片 | 首启那趟跳过了（postgres 还没起）。下一次合入就会跑到 —— 只是没人去看结果 |
| **1** 封印 | `healthcheck.yml` 第三级的 `vault status` 退出码假设；`deploy.yml` 的 `vault_sealed → 不回滚` 分支 | 生产上某次主机重启之后。判定错了的表现是「把好镜像换成旧镜像，然后仍然 503」 |
| **2** 回滚 | `deploy.yml` 的 `rescue`、`rollback.yml` 全部、`previous-image` 记账、回滚时的 `pull: always` | 一次真实的发布事故当场。而且此刻 `previous-image` 很可能是**空的**（见前置检查第 2 条），也就是回滚功能处于不可用状态而没人知道 |

演练 2 有一个**已知不覆盖**的分支，见文末「本次演练不覆盖什么」——
跳过它可以，但要知道缺口在哪。

---

## 前置检查

全部在**运维本机**执行，`cd infra/ansible`。任何一条不满足就先补齐，别硬上。

```bash
# 1. 本机能解密 staging 凭据（age 私钥在位）。期望：✓
sops -d inventory/group_vars/ncards_staging/secrets.sops.yaml >/dev/null && echo "✓ age 私钥在位"

# 2. ⚠️ 有可回滚的目标吗 —— 演练 2 的硬前提
ssh -p 2242 deploy@api.staging.n-cards.de \
  'cat /opt/ncards/state/current-image; echo "--- previous:"; cat /opt/ncards/state/previous-image 2>/dev/null || echo "(不存在)"'
```

> ⚠️ **首启之后 `previous-image` 一定是不存在或空的。** `deploy.yml` 记录它的条件是
> 「.env 里读到了非空的旧 `APP_IMAGE`」，而首次部署时 `.env` 还不存在。
> 也就是说**至少要有两次成功部署**，回滚才有目标 —— 这正是演练 0 排在最前面的原因。
> 此时直接做演练 2，只会撞上 `确认有可回滚的目标` 那条断言，什么也验不到。

```bash
# 3. 磁盘余量。期望：可用 ≥ 5 GB（部署前的断言线）
ssh -p 2242 deploy@api.staging.n-cards.de 'df -h /'

# 4. 现在是健康的（演练要从一个已知的好状态开始）。期望：200 / 200
curl -sS -o /dev/null -w 'live %{http_code}\n' https://api.staging.n-cards.de/health/live
curl -sS -o /dev/null -w 'ready %{http_code}\n' https://api.staging.n-cards.de/health/ready
```

**5. ⚠️ staging 的 5 把 unseal key 现在拿得到吗？**

这是演练 1 唯一不可回退的前置。演练会让 Vault 回到封印状态，**凑不齐 3 把就解不开**。
staging 丢了不致命（合成数据，重 init + bootstrap 即可，但那是半天的活），
生产上同样的疏忽就是一次数据永久丢失事件。**没确认就别往下走。**

**6. 挑一个没人用 staging 的窗口。** 演练 1 期间 `/health/ready` 会持续 503，
演练 2 期间 app 会有几秒中断。

---

## 演练 0：确认快照路径真的跑过（免费，但必须去看）

下一次 `main` 合入自动触发。T-101 已经落了第一条迁移，所以这一趟同时是
**快照与迁移在真机上的首次执行**。

在 `deploy-staging` 的日志里找这两行：

```
TASK [ncards_stack : 报告快照]
ok: [staging.n-cards] => 快照 /var/backups/ncards/20260905T…-<sha前12位>.sql.gz（12.4 KB）
```

```bash
# 主机侧确认。期望：文件在、owner root、权限 0600、大小 > 100 字节
ssh -p 2242 deploy@api.staging.n-cards.de 'sudo ls -lh /var/backups/ncards/'
```

| 看到什么 | 说明 |
|---|---|
| `报告快照 … KB` | ✅ 路径通了。记下大小与这一步的耗时 |
| `postgres 没有在运行 —— 这台机器上还没有数据库，跳过部署前快照` | ⚠️ postgres 没起来。这是**故障**，不是首启了 —— 去看 `docker compose ps` |
| `确认快照不是空的` 失败（< 100 字节） | `pg_dump` 没真的跑。看上一条 task 的 stderr，多半是库名/用户名对不上 |

做完这一步，前置检查第 2 条应该已经变成「`previous-image` 非空且 ≠ `current-image`」。
**这是演练 2 的放行条件。**

---

## 演练 1：封印演练（约 20 分钟）

**要证明的一句话**：Vault 封印时，部署流水线**不回滚**，只是红着等人来 unseal。

### 1.1 记录起点

```bash
ssh -p 2242 deploy@api.staging.n-cards.de
cd /opt/ncards
export COMPOSE_FILE=infra/compose/docker-compose.base.yml:infra/compose/docker-compose.prod.yml:infra/compose/docker-compose.staging.yml

cat state/current-image state/previous-image
docker inspect -f '{{.State.StartedAt}}' $(docker compose ps -q app)   # ← 记下来，演练后要对比
docker compose exec vault vault status -address=http://127.0.0.1:8200; echo "rc=$?"
#   期望：Initialized true · Sealed false · rc=0
```

### 1.2 封印

**用重启，不用 `vault operator seal`** —— 后者要一个带 `sudo` 权限的 token，
而 root token 在首启第 10 步已经吊销了。重启也更接近真实场景（主机重启、内核升级）。

```bash
docker compose restart vault
sleep 5
docker compose exec vault vault status -address=http://127.0.0.1:8200; echo "rc=$?"
#   期望：Initialized true · Sealed true · rc=2   ← 这个 2 就是要验的假设
```

> ⚠️ **`rc=2` 是本次演练最有信息量的一个数字。** `healthcheck.yml` 第三级把
> rc ∈ {1,2} 判为 `vault_sealed`，这个映射此前只有注释（「1.18 实测都给 2」），
> 没有在这台机器上、经 `docker compose exec -T` 验证过。
> 若实得 0 或别的值，**停止演练**，先修 `healthcheck.yml` —— 那意味着真实的封印
> 会被判成 `broken`，然后触发一次纯粹有害的回滚。

```bash
curl -sS -o /dev/null -w 'live %{http_code}\n' https://api.staging.n-cards.de/health/live    # 期望 200
curl -sS -o /dev/null -w 'ready %{http_code}\n' https://api.staging.n-cards.de/health/ready  # 期望 503
```

### 1.3 先只看判定（不动现网）

在**运维本机**：

```bash
cd infra/ansible
ansible-playbook -i inventory/staging.yml deploy.yml --tags health
```

**约 1 分钟**，不是秒回：Vault 封印时 `/health/ready` 一直 503，
`探测 /health/ready` 会把 `ncards_health_retries: 12 × ncards_health_delay: 5` 走满。
这是期望行为，别以为卡住了。

```
TASK [ncards_stack : 报告判定结果]
ok: [staging.n-cards] => 判定 vault_sealed（/health/live 200、/health/ready 503、vault status rc 2）
```

> ⚠️ **PLAY RECAP 是空的（一行主机都没有）＝ 一条任务都没跑，不是「跑完了没事」。**
> 说明 `--tags health` 没匹配上任何任务。`deploy.yml` 曾经就是这样：role 是用
> `include_role`（动态）拉进来的，`health` 这个 tag 在 role 内部的 `main.yml` 上，
> 而 `--tags` 在解析期就把没有 tag 的 include 本身滤掉了 —— role 压根没被打开。
> 已在 `deploy.yml` 给那个 include 标了 `tags: [always]` 修掉（演练 1 的第一个发现）。
> 若又见到空 RECAP，先 `ansible-playbook … --list-tags` 确认 tag 到底透出来没有。

> `--tags health` 只跑健康判定：`preflight` / `env` / `snapshot` / `deploy` 与
> play 末尾那三条处置任务都没有 tag，会被跳过。所以这一步**只读**，不会碰现网。
> 也正因为如此，它验的只是「判定对不对」，**没有**验「判定之后做了什么」——
> 那是下一步。

### 1.4 跑一次完整部署，验证「不回滚」

```
Actions → deploy-manual → Run workflow
  environment:     staging
  image_tag:       <当前 current-image 的那个 40 位 sha>     ← 部署同一个镜像
  run_migrations:  false
```

部署同一个 sha 是刻意的：`记录回滚目标` 那一步带 `!= ncards_app_image` 的条件，
所以这一趟**不会**改写 `previous-image`；`起栈` 对一个配置未变的服务也是空操作。
演练只想验判定分支，不想动别的变量。

**期望**：job **红**，且日志末尾是这一段（而不是任何回滚动作）：

```
TASK [判定 vault_sealed —— 不回滚，红着等人来 unseal]
fatal: [staging.n-cards]: FAILED! => ::error title=VAULT_SEALED::部署本身成功（/health/live 200），
但 Vault 处于封印或未初始化状态，/health/ready 返回 503。**这不是部署故障，回滚无用。**
```

**四条必须逐条确认的断言**：

```bash
ssh -p 2242 deploy@api.staging.n-cards.de
cd /opt/ncards && export COMPOSE_FILE=infra/compose/docker-compose.base.yml:infra/compose/docker-compose.prod.yml:infra/compose/docker-compose.staging.yml

cat state/current-image                                        # ① 与 1.1 相同（没被换成旧镜像）
cat state/previous-image                                       # ② 与 1.1 相同
docker inspect -f '{{.State.StartedAt}}' $(docker compose ps -q app)   # ③ 与 1.1 相同（app 没被重启）
grep '^APP_IMAGE=' infra/compose/.env                          # ④ 仍是那个好镜像
```

> ⚠️ 日志里出现 `回滚：… → …` 任何一行，都说明这个分支是坏的。
> 此时立刻按 [`deploy-and-rollback.md`](deploy-and-rollback.md) 确认现网状态，
> 并把 `healthcheck.yml` / `deploy.yml` 的判定当成 bug 处理 —— 这正是演练的目的。

### 1.5 解封并收尾

按 [`vault-unseal.md`](vault-unseal.md) 的**情形 A**：3 位持有人各输一把 key。

> 演练目的只是验证判定分支，技术上一个人输 3 把也能解开。
> 但既然人已经凑齐了，**顺便按真实流程走一遍**是免费的 —— 计时、记录卡在哪，
> 都是 T-406 「未参与实现的人照着 runbook 能完成 unseal」那条验收标准的预演。

```bash
curl -sS -o /dev/null -w 'ready %{http_code}\n' https://api.staging.n-cards.de/health/ready
#   期望：200，且**不需要**重新部署 —— ready 会自己转过来
```

想让流水线也变绿：把 1.4 那次 run 重跑一遍（Re-run failed jobs）。

---

## 演练 2：回滚演练（约 40 分钟）

**要证明的一句话**：部署失败时，现网能自动退回上一个能跑的镜像。

### 2.1 触发方式：部署一个 GHCR 上不存在的 tag

```bash
cd infra/ansible
ansible-playbook -i inventory/staging.yml deploy.yml \
  -e ncards_app_image=ghcr.io/ht-lin/n-cards-backend:deaddeaddeaddeaddeaddeaddeaddeaddeaddead \
  -e ncards_run_migrations=false
```

> **为什么走本机 ansible 而不是 `deploy-manual`**：`deploy-manual` 的 `guard` 会先
> 校验镜像在 GHCR 上存在 —— 那是刻意的（把「tag 打错了」挡在 approval 之前），
> 于是它也把这次演练挡住了。本机直跑用的是同一份 playbook，只绕过 guard。
>
> **为什么用「拉不到镜像」而不是「一个坏掉的镜像」**：`app` 的容器 healthcheck 是
> `curl -fsS http://localhost:8080/health/live`，而 `起栈` 带 `wait: true` ——
> 任何替身镜像（nginx、busybox…）都过不了那条 healthcheck，compose 会先超时失败。
> 也就是说随手找个坏镜像，走的**仍然**是 `rescue` 这条路，只是多花 120 秒等超时。
> 真正能走到「起来了但外部探针红」的镜像要专门造，见文末。
>
> ⚠️ `-e ncards_run_migrations=false`：演练不需要迁移，也不该在演练里制造 schema 变更。

### 2.2 期望的任务序列（照着对，顺序不能少）

```
TASK [ncards_stack : 报告快照]              → 快照 /var/backups/ncards/…（… KB）   ← 快照路径的第二次验证
TASK [ncards_stack : 记录回滚目标]           → changed（写入当前的好镜像）
TASK [ncards_stack : 拉取目标镜像]           → fatal（manifest unknown / not found）
TASK [报告失败步骤]                          → 部署在 “拉取目标镜像” 失败：…
TASK [检查 .env 是否已被改成本次镜像]         → rc=0（已切 —— 所以该回滚）
TASK [判定是否有可回滚的目标]                 → 非空且 ≠ 本次镜像
TASK [ncards_stack : 报告回滚动作]           → 回滚：…:deaddead… → …:<好 sha>
TASK [ncards_stack : 只重启 app]            → changed
TASK [部署失败]                             → fatal（这是**期望**的收尾）
```

> ⚠️ **`rescue` 这条路径回滚之后不会重新判定健康** —— 那只发生在
> `verdict == broken` 那条分支上。所以下面 2.3 的验证必须人工做，不能只看 playbook 绿不绿
> （它本来就以 `部署失败` 收尾）。

### 2.3 验证（五条，一条都别省）

```bash
ssh -p 2242 deploy@api.staging.n-cards.de
cd /opt/ncards && export COMPOSE_FILE=infra/compose/docker-compose.base.yml:infra/compose/docker-compose.prod.yml:infra/compose/docker-compose.staging.yml

grep '^APP_IMAGE=' infra/compose/.env      # ① 回到了好镜像，不是 deaddead…
cat state/current-image                    # ② 同上
sudo ls -lh /var/backups/ncards/           # ③ 比演练前多了一份快照
```

```bash
curl -sS -o /dev/null -w 'live %{http_code}\n' https://api.staging.n-cards.de/health/live    # ④ 200
curl -sS -o /dev/null -w 'ready %{http_code}\n' https://api.staging.n-cards.de/health/ready  # ④ 200
```

```bash
# ⑤ ⚠️ 最容易被忘掉的一条：Vault 仍然是解封的
docker compose exec vault vault status -address=http://127.0.0.1:8200 | grep Sealed
#   期望：Sealed  false
```

> 第 ⑤ 条验的是 `rollback.yml` 里「只重启 app，刻意不动 vault」那段注释的推理：
> 回滚若顺手重启了 vault，Vault 会重新封印，于是一次**自动**恢复变成一次需要
> 找 3 个人的**人工事故**。这条推理此前从未被验证过。

顺手把 M0 表里那个「待测」的数字补上：

```bash
docker images ghcr.io/ht-lin/n-cards-backend --format '{{.Tag}}\t{{.Size}}'   # 后端镜像大小
```

### 2.4 演练留下的状态（不需要修，但要知道）

回滚之后 `previous-image` 与 `current-image` **指向同一个镜像**（记账发生在拉取
之前，所以记下的是那个好镜像）。后果只有一个：**此刻手工跑 `rollback.yml` 是一次
空重启**。下一次正常部署会重新记账，不需要人工干预。

---

## 失败回滚（演练本身出事了怎么办）

**演练 2 的自动回滚没成功，现网还挂着**

```bash
ssh -p 2242 deploy@api.staging.n-cards.de
cd /opt/ncards
export COMPOSE_FILE=infra/compose/docker-compose.base.yml:infra/compose/docker-compose.prod.yml:infra/compose/docker-compose.staging.yml

sudo sed -i "s|^APP_IMAGE=.*|APP_IMAGE=$(cat state/previous-image)|" infra/compose/.env
docker compose up -d --pull always app
curl -sS -o /dev/null -w '%{http_code}\n' https://api.staging.n-cards.de/health/ready
```

**演练 1 之后凑不齐 3 把 key** —— 见 [`vault-unseal.md`](vault-unseal.md) 的
「只凑得齐 2 把 key」。⚠️ **不要** `operator init`。staging 上重 init 是可行的
（合成数据），但那是 [`staging-first-boot.md`](staging-first-boot.md) 第 8–10 步整套重来，
且必须重签 AppRole 并改 sops。**这就是前置检查第 5 条不能跳的原因。**

**解封之后 `/health/ready` 仍然 503** —— 不是 unseal 问题，多半是 `secret_id`。
见 `vault-unseal.md` 的「升级路径」。

**磁盘满了** —— 见 [`deploy-and-rollback.md`](deploy-and-rollback.md) 的「磁盘满了」。
⚠️ 演练期间尤其不要 `docker system prune -af`：它会删掉回滚目标。

**任何时候都不要** `docker compose down -v` —— 删掉 `vault_data` 就是全部密文永久不可解密。

---

## 演练记录表（**开工前就打开这张**，边做边填，然后回填 [`docs/tasks/M0.md`](../tasks/M0.md)）

> ⚠️ 2026-09-05 那次是**做完之后**才来填这张表的，结果只剩 `rc=2` 一个数字 ——
> 三段演练全跑通了，却回答不了「回滚要多久才能恢复」。
> 这些数字的用处正是在应急时估时间，事后补不回来。**先开表，再动手。**

| 项 | 值 |
|---|---|
| 日期 / 执行人 | |
| **演练 0** 快照文件大小 / `报告快照` 那步耗时 | |
| 首次真实迁移耗时（T-101 的那条） | |
| **演练 1** 封印后 `vault status` 退出码（期望 2） | |
| 判定字符串（期望 `vault_sealed`，含三个探测值） | |
| 是否触发回滚（期望：**否**） | |
| `current-image` / `previous-image` / app `StartedAt` 是否变化（期望：均否） | |
| unseal 实际人数 / 耗时 / 卡在哪一步 | |
| 解封后 `/health/ready` 自行转 200 的等待时间 | |
| **演练 2** 从 playbook 开始到 `ready` 200 的总耗时 | |
| 回滚时是否需要重新从 GHCR 拉镜像（本地还在吗） | |
| 回滚后 Vault 是否仍解封（期望：**是**） | |
| 后端镜像大小（顺手补 M0 的「待测」） | |
| 发现的问题 | |

> §9.4 的口径是「未演练的备份视为不存在」。同理：**未演练的回滚视为不存在**。
> 演练跑过即满足 §13.8 —— 但**没填这张表的演练只证明了「它没坏」，
> 给不出任何可用于决策的数字**（2026-09-05 那次就是如此）。两件事都要做。

---

## 本次演练不覆盖什么

**`verdict == broken` → 回滚 → 重新判定** 这条分支验不到。它要求一个「容器
healthcheck 过、但外部探针红」的镜像，而演练 2 的触发方式（拉不到镜像）走的是
`rescue`，两条路径的回滚**入口不同**：`rescue` 那条回滚后不重判，`broken` 那条会
重判并在仍不健康时打印「⚠️ 回滚之后仍然不健康」。

要补上这一档，需要造一个只监听回环地址的替身镜像 —— 容器内 `curl localhost:8080`
通过（healthcheck 绿），Caddy 从容器网络连 `app:8080` 连不上（外部 502）：

```dockerfile
FROM alpine:3.20
RUN apk add --no-cache busybox-extras curl && mkdir -p /srv/health && printf ok > /srv/health/live
CMD ["httpd", "-f", "-p", "127.0.0.1:8080", "-h", "/srv"]
```

代价：要推一个临时 tag 到 GHCR（主机上禁止构建镜像，而 `拉取目标镜像` 只认拉得到的
tag），演练完记得删掉那个 tag。**判断要不要做**：它验的是「一个能起来但坏掉的版本
被自动挡下来」——也就是真实发布事故里最常见的形状。M0 阶段可以接受不做，
但 T-405 / 上线前应该补上。

**其他明确不在本手册范围内的**：从快照恢复（人工决策，见
[`deploy-and-rollback.md`](deploy-and-rollback.md)）、`vault_data` 的备份与恢复演练
（T-406，且**目前根本没有备份**）、生产环境的任何演练（生产 inventory 还是骨架）。

---

## 升级路径（找谁）

| 情况 | 找谁 / 怎么办 |
|---|---|
| 封印后凑不齐 3 把 unseal key | **技术负责人**。staging 可重建，但按事故记录 —— 同样的疏忽在生产上是数据永久丢失 |
| `vault status` 退出码不是 2 | 停止演练，改 `healthcheck.yml` 的映射并补一条针对真实退出码的断言 |
| `vault_sealed` 判定触发了回滚 | `deploy.yml` 的分支有 bug。现网多半已被换成旧镜像，先按 `deploy-and-rollback.md` 恢复 |
| 演练 2 后现网起不来，且回滚也救不回来 | 见 `deploy-and-rollback.md` 的「回滚也没救回来」。**不要**自行恢复快照 —— 那个决定不要一个人做 |
| 主机连不上 | Hetzner Cloud Console 的网页终端 |
