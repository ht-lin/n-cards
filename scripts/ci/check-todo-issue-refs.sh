#!/usr/bin/env bash
#
# TODO 检查（§13.3 的「通用」表第三行）：`TODO` 必须带 issue 编号 —— `// TODO(#123): …`
#
# 为什么要有编号：没有编号的 TODO 不会被任何人再看见。它既不在 backlog 里，
# 也不会过期，最后变成一句「以前有人觉得这里不对」。带编号的 TODO 是一条有主的债。
#
# ⚠️ **只匹配标记形态**（TODO 后面紧跟冒号或左括号），不匹配裸词 TODO。
# 这句话本身刻意不写出那两个标记形态 —— 写出来，这个脚本会报自己违规。
# 这不是偷懒 —— 本仓库的注释是中文的，裸匹配会把这些全判成违规：
#     android/detekt.yml     「`TODO` 必须带 issue 编号」由 T-011 的通用扫描负责
#     .github/workflows/*    「gitleaks、敏感日志扫描、TODO 检查」
# 代价是 `// TODO 修一下`（既无冒号也无括号）漏网。取舍是清楚的：一条永远误报的
# 门禁会在两周内被所有人加进忽略列表，那时它一条也挡不住。
#
# detekt 的 ForbiddenComment 刻意关着（detekt.yml 第 55–58 行）把这件事让给本脚本 ——
# 后端与 Android 必须是同一条规则，两处实现必然会漂。
#
#   用法: scripts/ci/check-todo-issue-refs.sh [仓库根]
#   默认: 从脚本位置推断
#
# 依赖: git、grep -E
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
    case "$f" in
        # 文档里的 TODO 是在**谈论**这条规则，不是欠的债。
        docs/*|*.md) continue ;;
        # 生成产物禁止手改（§13.1 第 4 条），在这里报出来没有可执行的出路。
        */generated/*) continue ;;
        # 自检脚本的夹具里**必须**有一条无编号的 TODO —— 那正是它要断言被拦下的
        # 东西。这是全仓唯一的豁免，敏感日志扫描不需要对应的一条（它的作用域是
        # backend/*.php 与 android/*/src/*.kt，本来就够不着 scripts/）。
        scripts/ci/sensitive-scan-selftest.sh) continue ;;
    esac
    files+=("$f")
done < <(git ls-files -z)

[ "${#files[@]}" -eq 0 ] && { echo "✓ TODO 检查：没有可扫描的文件"; exit 0; }

# 标记形态的候选，减去合规形态 `TODO(#123):`。
candidates="$(grep -nHE 'TODO[[:space:]]*(\(|:)' "${files[@]}" 2>/dev/null || true)"
violations="$(printf '%s' "$candidates" | grep -vE 'TODO[[:space:]]*\(#[0-9]+\)[[:space:]]*:' || true)"

if [ -n "$violations" ]; then
    echo "!! 以下 TODO 没有 issue 编号（§13.3）"
    printf '%s\n' "$violations" | sed 's/^/     /'
    cat >&2 <<'EOF'

正确形态：
    // TODO(#123): 等 T-101 装上 ORM 之后把这一步换成 doctrine:schema:validate

先开 issue 再写 TODO。不打算开 issue 的，就别留 TODO —— 直接删掉，或者把结论
写成一段说明为什么现在这样做的注释（本仓库到处都是这种注释，那才是有效的形态）。
EOF
    exit 1
fi

echo "✓ TODO 检查：${#files[@]} 个文件，全部 TODO 都带 issue 编号"
