# Runbook：部署失败与回滚

`deploy-staging` 红了怎么办。**先看判定结果，再决定动作** —— 三种判定对应三种
完全不同的处置，其中一种（`vault_sealed`）**不该**回滚。

## 触发条件

- `main.yml` 的 `deploy-staging` 失败
- `deploy-manual.yml` 失败
- 部署当时是绿的，但之后发现新版本有问题

## 前置检查

先在流水线日志里找这一行（Ansible 的「报告判定结果」任务）：

```
判定 broken（/health/live 200、/health/ready 503、vault status rc 0）
```

对照下表，**别急着回滚**：

| 判定 | 含义 | 该做什么 |
|---|---|---|
| `ok` | 一切正常 | 部署成功。job 红的话是冒烟测试那一步的问题，往下看「冒烟测试红了」 |
| `vault_sealed` | 部署成功，但 Vault 封印 | **不回滚**，去 unseal |
| `broken` | 应用真的坏了 | 已自动回滚，往下看「回滚之后」 |

---

## 情形 A：`VAULT_SEALED`（最常见，且不该回滚）

日志里有一段带 `::error title=VAULT_SEALED::` 的信息。

**这不是部署故障。** 主机重启后 Vault 一定是封印状态
（[ADR-0004](../adr/0004-manual-vault-unseal.md)：auto-unseal 关闭），
此时 `/health/ready` 返回 503 是**期望行为**。部署本身是成功的 ——
`/health/live` 已经 200，新镜像在跑。

回滚在这里是**有害**的：它会把一个好镜像换成旧镜像，然后仍然 503。

### 步骤

按 [`vault-unseal.md`](vault-unseal.md) 找 3 位 unseal key 持有人解封。

```bash
# 解封后验证。**不需要**重新部署 —— ready 会自己转 200。
curl -sS -o /dev/null -w '%{http_code}\n' https://api.staging.n-cards.de/health/ready
# 期望：200
```

想让流水线也变绿，重跑那次 `deploy-staging` 即可（Actions → Re-run failed jobs）。

---

## 情形 B：`broken` —— 已自动回滚

流水线已经把 `.env` 的 `APP_IMAGE` 换回上一个 tag、重启了 `app`、重新判定过一次。
日志末尾会说回滚后的判定是什么。

### B1. 回滚后判定是 `ok`

现网已经恢复到上一个版本，**不急**。去修引入问题的那个 commit，
下一次 main 合入会自动部署修复版。

确认现在跑的是哪个镜像：

```bash
ssh -p 2242 deploy@api.staging.n-cards.de 'cat /opt/ncards/state/current-image'
```

### B2. 回滚后仍然不健康 —— ⚠️ 问题不在这次的镜像

上一个版本也起不来，说明坏的是**依赖**而不是应用代码。按可能性排查：

```bash
ssh -p 2242 deploy@api.staging.n-cards.de
cd /opt/ncards
export COMPOSE_FILE=infra/compose/docker-compose.base.yml:infra/compose/docker-compose.prod.yml:infra/compose/docker-compose.staging.yml

docker compose ps            # 哪个容器不是 Up / healthy
docker compose logs --tail=80 app
docker compose logs --tail=40 postgres redis vault
df -h /                      # 磁盘满了？见下面「磁盘满了」
free -m                      # 内存被 OOM killer 收割过？dmesg -T | grep -i oom
```

最常见的三个原因：

1. **磁盘满** → 见下节。
2. **迁移改坏了 schema，旧镜像跑不了。** 这正是
   [ADR-0010](../adr/0010-rollback-image-tag-only.md) 说的那种情况 ——
   迁移**不会**被自动回滚。见下面「从快照恢复」。
3. **Postgres 被 OOM killer 杀了**（4 GB 机器上部署峰值）。
   确认 swap 在：`swapon --show`。不在的话跑一次 `site.yml` 补上。

---

## 情形 C：冒烟测试红了但判定是 `ok`

应用是好的，红在外部可见的东西上。`scripts/ci/smoke-staging.sh` 会指出是哪一条：

| 红在哪 | 多半是 |
|---|---|
| 证书不受信 | `ACME_CA` 还指着 Let's Encrypt 的 staging 目录 —— 改回生产目录重新部署 |
| 少某个安全头 | `infra/caddy/Caddyfile` 被改动过，或 Caddy 没加载新配置 |
| 有 `server:` / `x-powered-by:` | 同上；PHP 侧还有 `expose_php=Off` 一道 |
| HTTP 没有 308 | `CADDY_SITE_ADDRESS` 带了 `https://` scheme —— 裸域名才有自动重定向 |

---

## 手工回滚

自动回滚也失败了，或者部署当时是绿的、事后才发现问题：

```bash
cd infra/ansible
ansible-playbook -i inventory/staging.yml rollback.yml
# 期望：末尾「回滚完成，判定 ok」
```

要回到**更早**的版本（不止上一个），用完整 sha 走手动部署：

```
Actions → deploy-manual → Run workflow
  environment: staging
  image_tag: <完整 40 位 sha>
  run_migrations: false     ← 往回走时通常不要再跑迁移
```

> 回滚深度只有 1 步（只记一个 `previous-image`），本地镜像保留 3 个。
> 更早的 tag 本地可能已被清理，但 GHCR 上还在，会重新拉。

---

## 从快照恢复（**人工决策，不自动**）

⚠️ **只在确认「迁移改坏了数据」时才做。** 恢复会丢掉快照之后写入的一切。

快照在 `/var/backups/ncards/<时间戳>-<sha前12位>.sql.gz`，保留最近 10 份，
owner root / 0600 —— 所以下面几条命令都要 `sudo`。

```bash
ssh -p 2242 deploy@api.staging.n-cards.de
sudo ls -lh /var/backups/ncards/      # 挑一份，确认时间戳在出问题的部署**之前**
```

```bash
cd /opt/ncards
export COMPOSE_FILE=infra/compose/docker-compose.base.yml:infra/compose/docker-compose.prod.yml:infra/compose/docker-compose.staging.yml

# 1. 停掉写入方（只停 app，别停 postgres）
docker compose stop app

# 2. 恢复。⚠️ 这会覆盖当前库。
sudo gunzip -c /var/backups/ncards/<选定的文件>.sql.gz \
  | docker compose exec -T postgres psql -U ncards -d ncards

# 3. 把镜像也退回与这份快照匹配的那个版本，再起 app
docker compose start app
curl -sS -o /dev/null -w '%{http_code}\n' https://api.staging.n-cards.de/health/ready
# 期望：200
```

> ⚠️ 这份快照**不是备份系统**：无异地、无 WAL、无加密，只在部署那一刻存在
> （[ADR-0010](../adr/0010-rollback-image-tag-only.md)）。真正的备份与恢复演练
> 是 T-406 的 `restore-from-backup.md`。**生产启用前必须先由 T-406 的方案替换
> 这一节。**

---

## 磁盘满了

40 GB 的盘，部署前会断言至少 5 GB 可用。触发了那条断言：

```bash
ssh -p 2242 deploy@api.staging.n-cards.de
df -h / && docker system df
sudo du -sh /var/backups/ncards/
```

**⚠️ 绝对不要跑 `docker system prune -af`** —— 它会删掉所有没在跑的镜像，
包括 `state/previous-image` 指向的那个回滚目标。下次部署失败时才会发现回不去了。

安全的清理，按顺序：

```bash
docker image prune -f                              # 只清 dangling，安全
docker builder prune -f                            # 构建缓存（主机上本来就不该有）
sudo ls -t /var/backups/ncards/*.sql.gz | tail -n +6 | sudo xargs -r rm   # 只留最近 5 份
docker compose logs --tail=0 -f &                  # 日志已由 daemon.json 轮转（10m×3）
```

---

## 三条禁令

改任何部署相关的东西之前，先记住这三条：

1. **绝不 `docker compose down`，更绝不 `down -v`。**
   后者会删掉 `vault_data`（= 全部卡数据永久不可解密，§9.4）、
   `pg_data`、以及 `caddy_data`（证书没了，重签还可能撞上 Let's Encrypt 速率限制）。
2. **绝不在主机上构建镜像。** 部署的必须是 CI 验证过的那一个。
   主机上根本没有 `backend/` 源码，误触发的构建会硬失败 —— 那是刻意的。
3. **绝不把生产数据搬进 staging**，哪怕脱敏（§14.1）。需要真实规模就用生成器造数。

## 升级路径（找谁）

| 情况 | 找谁 |
|---|---|
| `VAULT_SEALED` | 3 位 unseal key 持有人（[`vault-unseal.md`](vault-unseal.md)） |
| 回滚后仍不健康、且怀疑数据被改坏 | 先**停止一切写入**（`docker compose stop app`），再叫人一起判断要不要恢复快照 —— 这个决定不要一个人做 |
| 主机连不上 | Hetzner Cloud Console 的网页终端 |
| GHCR 拉不到镜像 | 确认包的可见性没被改回 private；确认那个 sha 的 `backend-image` job 真的绿过 |
| 证书问题 | 先自查 DNS / ufw 80 / `docker compose logs caddy`；撞速率限制就等，别反复重试（越试越久） |
