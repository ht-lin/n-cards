#!/usr/bin/env bash
#
# Secret 扫描（§13.3 的「通用」表第一行：`gitleaks`，0 命中）。
#
# **只扫 git 跟踪的文件。** `gitleaks dir .` 会连 node_modules / vendor /
# */build / backend/var 一起扫 —— 实测 757 条命中，其中 744 条来自这些目录，
# 而它们一个字节都不入库。做法是把跟踪文件复制进临时目录再扫，与
# check-sensitive-logs.sh / check-todo-issue-refs.sh 用 `git ls-files` 取范围一致。
#
# 为什么不扫 git 历史（`gitleaks git`）：历史扫描是**一次性的清库动作**，
# 不该每个 PR 重跑一遍全部历史。历史里真进了东西，rotate 凭据 + rewrite 才是出路，
# 一条每次 PR 都红的门禁只会被加进忽略列表。
#
# 未提交的改动也会被扫到（复制的是工作区内容，不是 HEAD）——
# 但**未 git add 的新文件不会**。CI 上不存在这个盲区（checkout 出来的都是跟踪文件）。
#
#   用法: scripts/ci/check-gitleaks.sh [仓库根]
#
# 依赖: gitleaks（CI 由 shared.yml 装钉死版本；本地见下面的提示）、git、tar
#
set -euo pipefail

ROOT="${1:-$(cd "$(dirname "$0")/../.." && pwd)}"
cd "$ROOT"

if ! command -v gitleaks >/dev/null 2>&1; then
    cat >&2 <<'EOF'
!! 本机没有 gitleaks。

   本地装（版本要与 .github/workflows/shared.yml 里钉的一致）：
     v=8.30.1
     curl -sSL "https://github.com/gitleaks/gitleaks/releases/download/v${v}/gitleaks_${v}_linux_x64.tar.gz" \
       | tar -xz -C ~/.local/bin gitleaks

   跳过这一条不影响其余门禁 —— CI 上它是必跑的。
EOF
    exit 127
fi

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "!! $ROOT 不是 git 工作区" >&2
    exit 2
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# 跟踪文件的**工作区内容**打包再解开。用 tar 而不是 `git archive` 是刻意的：
# git archive 取的是 HEAD，本地改了还没 commit 的内容就扫不到了。
git ls-files -z | tar --null --files-from=- -cf - | tar -xf - -C "$WORK"

# ⚠️ 必须 `cd` 进去再扫 `.`，不能 `gitleaks dir "$WORK"`：源路径给的是绝对路径时，
# 报告里的 File 也是绝对路径（/tmp/tmp.XXXX/docs/api/…），于是 .gitleaks.toml 里
# 按 `paths` 写的豁免一条都匹配不上，而 gitleaks 不会为此报任何错 —— 表现是
# 豁免「写了但没生效」。
cd "$WORK"
gitleaks dir . \
    --config "$ROOT/.gitleaks.toml" \
    --redact \
    --no-banner \
    --exit-code 1
cd "$ROOT"

echo "✓ gitleaks：$(git ls-files | wc -l) 个跟踪文件，0 命中"
