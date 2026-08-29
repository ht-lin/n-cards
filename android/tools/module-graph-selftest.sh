#!/usr/bin/env bash
#
# 模块依赖规则本身的自检：故意写违规依赖，断言构建拦得下来。
#
# 与 build-logic 的 ModuleGraphTest 是**两件不同的事**，两个都要有：
#
#   - ModuleGraphTest 验的是**规则表对不对**（毫秒级，穷举正反例）。
#     它证明不了规则被接到了构建上 —— 谁把 NcardsModuleGraphPlugin 从
#     ncards.android.library 里删掉，那些测试依然全绿。
#   - 本脚本验的是**规则真的在构建里生效**（秒级，注入真实违规依赖）。
#     它证明不了规则表覆盖得全 —— 它只试四条。
#
# 与 backend/tools/deptrac-selftest.sh 是同一个思路：`./gradlew assembleDebug`
# 全绿只能说明「当前代码没违规」，说明不了「违规会被拦下」。
#
# 四个场景对应 §12.3 的四条规则：
#
#   ① feature → feature   —— T-008 验收标准原文点名的那条
#   ② core → data         —— core:* 不得依赖 data:* / feature:*
#   ③ feature → sync      —— sync 不得被 feature:* 直接依赖（feature 只经 Repository）
#   ④ data → app          —— 没有人可以依赖组装层
#
# 用法：android/tools/module-graph-selftest.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."

# 被注入的文件在脚本退出时一律还原 —— 包括断言失败与 Ctrl-C。
declare -a TOUCHED=()

# ⚠️ 结尾的 `return 0` 不是多余的。这是 EXIT trap，它的返回值**就是脚本的退出码**：
# 循环体最后一次 `[ -f … ] && mv …` 求值为假时，cleanup 会带着 1 返回，
# 于是四条断言全过、还打印了「全部通过」的脚本仍然以 exit 1 结束。
# 这个 bug 真的发生过（CI 第一次跑 T-008 就红在这里），而本地当时没发现 ——
# 因为验证时把脚本管道给了 `tail`，$? 拿到的是 tail 的退出码，不是脚本的。
# 改这个函数时请用 `tools/module-graph-selftest.sh >/dev/null 2>&1; echo $?` 验证，
# **不要**接管道。
cleanup() {
    local file
    for file in ${TOUCHED[@]+"${TOUCHED[@]}"}; do
        if [ -n "$file" ] && [ -f "$file.selftest-backup" ]; then
            mv -f "$file.selftest-backup" "$file"
        fi
    done
    return 0
}
trap cleanup EXIT

# 往 $1 模块的 build.gradle.kts 追加一条对 $2 的违规依赖，跑一次配置期，
# 要求它**失败**且错误文案里出现这两个模块路径。
#
# 跑的是 `help` 而不是 `assembleDebug`：规则在配置期就触发，因此不需要编译任何东西。
# 这也顺带证明了「构建失败」不是编译错误伪装的 —— 连一个任务都还没执行。
#
# $1 违规方模块目录 / $2 违规方项目路径 / $3 被依赖的项目路径 / $4 场景描述
assert_violation() {
    local module_dir="$1" from_path="$2" to_path="$3" description="$4"
    local build_file="$module_dir/build.gradle.kts"
    local output exit_code

    cp "$build_file" "$build_file.selftest-backup"
    TOUCHED+=("$build_file")

    cat >> "$build_file" <<EOF

// 由 tools/module-graph-selftest.sh 临时写入，脚本退出时还原。
dependencies {
    implementation(project("$to_path"))
}
EOF

    set +e
    output="$(./gradlew help --quiet --console=plain 2>&1)"
    exit_code=$?
    set -e

    mv -f "$build_file.selftest-backup" "$build_file"

    # 从待清理列表里摘掉。用重建而不是 `${TOUCHED[@]/$build_file}` ——
    # 那是模式**替换**，会把元素置空而不是删掉，留下一串空字符串给 cleanup。
    local remaining=() entry
    for entry in ${TOUCHED[@]+"${TOUCHED[@]}"}; do
        [ "$entry" = "$build_file" ] || remaining+=("$entry")
    done
    TOUCHED=(${remaining[@]+"${remaining[@]}"})

    if [ "$exit_code" -eq 0 ]; then
        echo "$output"
        echo
        echo "✗ 自检失败：${description}**没有**被拦下。"
        echo "  §12.3 的模块依赖规则已失效。检查 build-logic 的 ModuleGraph.kt 规则表，"
        echo "  以及 NcardsModuleGraphPlugin 是否还被 ncards.android.{library,application} 应用。"
        exit 1
    fi

    if ! grep -qF "$from_path" <<<"$output" || ! grep -qF "$to_path" <<<"$output"; then
        echo "$output"
        echo
        echo "✗ 自检失败：构建确实失败了，但报的不是预期的"
        echo "  $from_path -> $to_path 违规 —— 可能是别的错误顺带把构建弄红了。"
        exit 1
    fi

    echo "✓ ${description}被拦下（$from_path -> $to_path）。"
}

echo "模块依赖规则自检（§4.3 / §12.3）"
echo

# ---------------------------------------------------------------- 场景 ①
# T-008 验收标准：「故意在两个 feature 间加依赖会构建失败」。
assert_violation \
    'feature/wallet' ':feature:wallet' ':feature:scan' \
    'feature 之间互相依赖'

# ---------------------------------------------------------------- 场景 ②
# core:* 不得依赖 data:* / feature:*。
# 这条挡的是最隐蔽的一种腐化：core:ui 为了「就用一下那个 Repository」而依赖 data:card。
assert_violation \
    'core/ui' ':core:ui' ':data:card' \
    'core 依赖 data'

# ---------------------------------------------------------------- 场景 ③
# sync 不得被 feature:* 直接依赖（feature 只经 Repository）。
# 漏了这条，UI 会绕过 Repository 直接调 SyncEngine，
# 而 §4.3 的「本地数据库是 UI 的唯一真相源」就此失守。
assert_violation \
    'feature/wallet' ':feature:wallet' ':sync' \
    'feature 直接依赖 sync'

# ---------------------------------------------------------------- 场景 ④
# 没有人可以依赖组装层。
assert_violation \
    'data/card' ':data:card' ':app' \
    'data 依赖 app'

echo
echo "✓ 模块依赖规则自检全部通过（4/4）。"
