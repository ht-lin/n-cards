# infra/ansible

Hetzner（Nürnberg）CCX23 主机配置与部署 playbook。**由 T-012 交付**，当前目录为空。

## 范围

- SSH 加固：仅密钥登录、禁 root、`fail2ban`、非 22 端口
- Docker 与 compose 栈部署
- `sops`（age）加密的配置下发
- 部署流程：前置数据库快照 → 滚动重启 → 迁移 → 健康检查 → 失败自动回滚上一镜像 tag

## 约束

Vault unseal key **绝不**进 Ansible secrets 或 CI。staging 只用合成数据。
