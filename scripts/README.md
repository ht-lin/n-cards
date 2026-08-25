# scripts

仓库运维脚本。全部要求：可重复执行（幂等）、失败时非零退出、不静默吞错。

| 脚本 | 用途 |
|---|---|
| [`setup-branch-protection.sh`](setup-branch-protection.sh) | 用 GitHub ruleset 配置 `main` 分支保护（§13.2）。规则写成脚本而非点 UI，才能被 review 与复现 |
