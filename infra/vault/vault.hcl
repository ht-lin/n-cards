# Vault 生产配置（§3.3 / §7.4 / Q6）。只被 docker-compose.prod.yml 挂载使用；
# 本地开发跑的是 dev 模式，不读本文件。
#
# ============================================================================
# 没有 seal stanza 是**刻意的**，不是漏写
# ============================================================================
# Q6 的决策是「人工 unseal + runbook」，auto-unseal **关闭**。
# 一旦配上 `seal "awskms"` / `seal "transit"` 之类的自动解封，unseal key 就等于
# 交给了另一个在线系统，§3.3 里「服务端加密能挡住什么」的那套论证会整个塌掉。
#
# 后果（写在这里省得有人半夜自己发现）：
#   - 每次 vault 容器重启后都是**封印**状态，必须人工 unseal（Shamir 3-of-5）
#   - 此期间 app 的 /health/ready 会失败，这是期望行为
#   - unseal key 离线保管，**绝不**进 CI、Ansible secrets 或任何仓库
#   - 步骤见 docs/runbooks/vault-unseal.md（T-005 交付初版，T-406 完善）
#
# ============================================================================
# 本文件的交付边界
# ============================================================================
# T-003 只负责让 vault 在生产形态下**起得来**。
# Transit 引擎（ncards-card / ncards-pii / ncards-hmac）、AppRole、policy 文件
# 全部属 T-005，见 §17.4。

ui = false

# 文件后端。单主机 Compose 起步（§4.1），还没到需要 Raft 集群的规模。
# 这个路径挂的是**独立**数据卷 vault_data，与 app 不共卷（§7.4 / T-003）。
storage "file" {
  path = "/vault/file"
}

listener "tcp" {
  # 只监听容器内网。compose 的 backing 网络是 internal: true，
  # 加上没有 ports: 映射，这个端口在宿主机上根本不可达（§7.4）。
  address = "0.0.0.0:8200"

  # TLS 由前面的 Caddy 终结；Vault 与 app 之间是 Docker 内网的明文。
  # 若将来拆成多主机，这里必须换成真实 TLS —— 到时候一并改 §4.1 的拓扑。
  tls_disable = 1
}

# mlock：不让密钥被换页到磁盘。compose 里给了 cap_add: IPC_LOCK 才能生效。
disable_mlock = false

# 审计日志到 stderr，由 Docker 收走（T-405 接 Loki）。
# 注意：Vault 的审计日志里请求/响应是 HMAC 过的，不含明文密钥。
api_addr = "http://vault:8200"
