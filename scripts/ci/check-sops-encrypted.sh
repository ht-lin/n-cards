#!/usr/bin/env bash
#
# sops 加密检查（T-012）：入库的 *.sops.yaml 必须是**密文**。
#
# 为什么需要这道门：sops 的工作方式是「密文入库、明文只在内存里」，而从明文模板
# 做出密文只有一步 `sops -e -i`。忘了那一步，得到的就是一个文件名叫 secrets.sops.yaml、
# 内容是明文口令的文件 —— 而它看起来和加密过的一样正常，git 也不会有任何抱怨。
#
# gitleaks 挡不住这个：.gitleaks.toml 对这个路径豁免了 generic-api-key（密文本身
# 是高熵串，不豁免的话每条 PR 都红）。那条豁免的前提，就是有这个脚本兜着。
# 两者是配套的，改一个必须回头看另一个。
#
# 判据（不需要 age 私钥，纯文本检查）：
#   1. 文件里有顶层 `sops:` 节点 —— sops 加密后一定会加上它
#   2. 除 sops: 子树外，每个标量叶子值都以 ENC[ 开头
#
# *.sops.yaml.example 是给人看的明文模板，不在检查范围内（见下面的排除）。
#
#   用法: scripts/ci/check-sops-encrypted.sh [仓库根]
#   默认: 从脚本位置推断
#
# 依赖: git、python3（PyYAML 不需要 —— 用行扫描，免得给 CI 多加一个依赖）
#
set -euo pipefail

ROOT="${1:-$(cd "$(dirname "$0")/../.." && pwd)}"
cd "$ROOT"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "!! $ROOT 不是 git 工作区 —— 文件清单取自 git ls-files" >&2
    exit 2
fi

files=()
while IFS= read -r -d '' f; do
    # ⚠️ 仓库根的 `.sops.yaml` 是 sops 的**规则配置**（哪个路径用哪把 age 公钥），
    # 不是被加密的文件 —— 而它同样匹配 `*.sops.yaml` 这个 glob。
    # 判据是「点号前面有没有名字」：secrets.sops.yaml 要查，.sops.yaml 不查。
    case "$(basename "$f")" in
        .sops.yaml|.sops.yml) continue ;;
    esac
    files+=("$f")
done < <(git ls-files -z -- '*.sops.yaml' '*.sops.yml')

if [ "${#files[@]}" -eq 0 ]; then
    # 首启第 6 步之前是正常状态：模板在库里，真文件还没生成。
    echo "✓ sops 检查：仓库里还没有 *.sops.yaml（首启前的正常状态）"
    exit 0
fi

violations=0

for f in "${files[@]}"; do
    if ! grep -qE '^sops:' "$f"; then
        echo "!! $f 没有顶层 sops: 节点 —— 它没有被加密过"
        violations=$((violations + 1))
        continue
    fi

    # 扫描 sops: 节点**之前**的部分（sops: 自己的元数据里有大量非 ENC[ 的值：
    # 版本号、lastmodified、age recipient 等，它们本来就该是明文）。
    plaintext="$(
        awk '
            /^sops:/ { exit }
            # 跳过注释与空行
            /^[[:space:]]*#/ { next }
            /^[[:space:]]*$/ { next }
            # 只看 `key: value` 形态里有值的行；值为空的是嵌套结构的父键
            /^[[:space:]]*[A-Za-z0-9_.-]+:[[:space:]]*$/ { next }
            /^[[:space:]]*[A-Za-z0-9_.-]+:[[:space:]]*.+$/ {
                line = $0
                sub(/^[[:space:]]*[A-Za-z0-9_.-]+:[[:space:]]*/, "", line)
                # 加密过的值形如 ENC[AES256_GCM,data:…]，可能带引号
                gsub(/^["'"'"']|["'"'"']$/, "", line)
                if (line !~ /^ENC\[/) { print FILENAME ": " $0 }
            }
        ' "$f"
    )"

    if [ -n "$plaintext" ]; then
        echo "!! $f 里有**未加密**的值："
        printf '%s\n' "$plaintext" | sed 's/^/     /'
        violations=$((violations + 1))
    fi
done

if [ "$violations" -gt 0 ]; then
    cat >&2 <<'EOF'

这些文件必须先加密再提交：

    sops -e -i <文件>          # 就地加密
    sops <文件>                # 之后要改值：解密到编辑器，存盘时自动重新加密

⚠️ 如果**已经把明文口令提交上去了**，改成密文是不够的 —— 明文还在 git 历史里。
必须把那些凭据全部作废重发：
    - APP_SECRET / POSTGRES_PASSWORD：改掉并重新部署
    - VAULT_SECRET_ID：在 Vault 里吊销并重新签发（docs/runbooks/vault-unseal.md）

age 公钥配置见仓库根 .sops.yaml；首启流程见 docs/runbooks/staging-first-boot.md。
EOF
    exit 1
fi

echo "✓ sops 检查：${#files[@]} 个文件，全部已加密"
