# scripts

仓库运维脚本。全部要求：可重复执行（幂等）、失败时非零退出、不静默吞错。

| 脚本 | 用途 |
|---|---|
| [`setup-branch-protection.sh`](setup-branch-protection.sh) | 用 GitHub ruleset 配置 `main` 分支保护（§13.2）。规则写成脚本而非点 UI，才能被 review 与复现 |
| [`ci/smoke-otp.py`](ci/smoke-otp.py) | 部署后走完一次真实 OTP 登录（T-114）。**唯一**一条打 `/v1` 的冒烟 —— 别的两条只验外部可见的东西，2026-09-19 那次登录整个不通时它们全绿 |

> ⚠️ `ci/smoke-otp.py` 是这里唯一一个不是 bash 的脚本。理由写在它的文件头：
> IMAP over TLS 在 bash 里只能靠 `openssl s_client` 手搓 UID 检索与正文解码，
> 那份代码没人看得懂也没人敢改。`imaplib` 在标准库里，没有新依赖。
> **这不是"以后都用 Python"的先例** —— 下一个脚本仍然默认 bash，除非同样有
> 一条"bash 做不了"的硬理由。
