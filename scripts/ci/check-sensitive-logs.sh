#!/usr/bin/env bash
#
# 敏感日志扫描（§13.3 的「通用」表第二行）。
#
# 三类规则，对应规格书原文「禁止 `dump(`、`var_dump`、`Log.d/v/i` 打印实体对象、
# 禁止日志中出现 `barcode_value` / `email` 变量名直接插值」：
#
#   ① PHP 调试输出   backend/ 下不得出现 var_dump( / dump( / dd( / print_r(
#   ② Kotlin 绕过 Timber  android/**/src/** 不得出现 android.util.Log
#      —— §7.3 要求 release 不输出任何日志，而这条只由 Timber 的 DebugTree
#      仅在 debug 种植来保证。直接用 android.util.Log 就绕过了那个保证。
#      规格书写的是 `Log.d/v/i`，这里连 import 一起挡：留着 import 不用它，
#      下一个人会以为可以用。
#   ③ 敏感字段插值   任何日志调用所在行不得出现 barcode_value / barcodeValue /
#      rawValue / email / refresh_token / refreshToken
#      （§14.4 的 Monolog 脱敏清单是服务端的**运行时**兜底；这一条是编译前的门禁，
#      两层都要有 —— 脱敏 processor 只认它认识的字段名。）
#
# ⚠️ **这是 grep 门禁，不是安全边界。** 把变量拆成两行、或换个名字都能绕过。
# 它挡的是失手，不是恶意。真正的防线是 code review 与 §14.4 的运行时脱敏。
#
# ⚠️ **注释行一律跳过**（`//` `*` `/*` `#` 开头）。不跳过的话第一次跑就红在自己人
# 身上：android/app/.../NcardsApplication.kt 的 KDoc 里逐字写着 `Timber.d(card)`，
# 讲的正是「Timber 在生产上是空操作」这件事。文档里引用一个反例不该被判违规。
#
# 与 detekt 不重叠：detekt.yml 第 60–62 行的注释写明了分工 —— detekt 的
# potential-bugs 管代码形态，这里管的是「什么东西不许进日志」。
#
#   用法: scripts/ci/check-sensitive-logs.sh [仓库根]
#   默认: 从脚本位置推断
#
# 依赖: git（文件清单取自 `git ls-files`，未入库的文件不扫）、grep -E
#
set -euo pipefail

ROOT="${1:-$(cd "$(dirname "$0")/../.." && pwd)}"
cd "$ROOT"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "!! $ROOT 不是 git 工作区 —— 文件清单取自 git ls-files" >&2
    exit 2
fi

# ---------------------------------------------------------------- 文件清单

php_files=()
kotlin_files=()

while IFS= read -r -d '' f; do
    case "$f" in
        # 生成产物不扫：openapi-generator 的输出禁止手改（§13.1 第 4 条），
        # 真要出问题也该在契约或生成器配置上修，不在这里。
        */generated/*) continue ;;
    esac

    case "$f" in
        backend/src/*.php|backend/config/*.php|backend/public/*.php|backend/tools/*.php|backend/bin/*)
            php_files+=("$f") ;;
    esac

    case "$f" in
        android/*/src/*.kt|android/*/src/*.kts)
            kotlin_files+=("$f") ;;
    esac
done < <(git ls-files -z)

# ---------------------------------------------------------------- 断言

failed=0

# grep 输出形如 `path:12:内容`；注释行的判定落在第二个冒号之后。
readonly COMMENT_LINE=':[0-9]+:[[:space:]]*(//|\*|/\*|#)'

# scan <规则名> <ERE> <文件…>
scan() {
    local name="$1" re="$2"
    shift 2
    [ "$#" -eq 0 ] && return 0

    local hits
    hits="$(grep -nHE "$re" "$@" 2>/dev/null | grep -vE "$COMMENT_LINE" || true)"
    if [ -n "$hits" ]; then
        echo "!! $name"
        printf '%s\n' "$hits" | sed 's/^/     /'
        echo
        failed=1
    fi
}

# ① `->dump(` 不算 —— 那是某个对象的方法，不是 VarDumper 的全局函数。
scan "PHP 调试输出（§13.3：禁 var_dump / dump / dd / print_r）" \
    '(^|[^[:alnum:]_$>])(var_dump|dump|dd|print_r)[[:space:]]*\(' \
    "${php_files[@]}"

scan "Kotlin 绕过 Timber（§7.3：禁 android.util.Log）" \
    '(^|[^[:alnum:]_.])Log\.[dvi][[:space:]]*\(|android\.util\.Log' \
    "${kotlin_files[@]}"

# ③ 两段式：先挑出日志调用所在行，再看这一行有没有敏感字段。
readonly LOG_CALL='(Timber\.[a-z]+[[:space:]]*\(|Log\.[a-z][[:space:]]*\(|->(debug|info|notice|warning|error|critical|alert|emergency|log)[[:space:]]*\()'
readonly SENSITIVE='(barcode_value|barcodeValue|rawValue|(^|[^[:alnum:]_])[eE]mail|refresh_token|refreshToken)'

scan_interpolation() {
    local files=("$@")
    [ "${#files[@]}" -eq 0 ] && return 0

    local hits
    hits="$(grep -nHE "$LOG_CALL" "${files[@]}" 2>/dev/null \
        | grep -E "$SENSITIVE" \
        | grep -vE "$COMMENT_LINE" || true)"
    if [ -n "$hits" ]; then
        echo "!! 敏感字段进日志（§13.3 / §14.4：barcode_value / email / refresh_token）"
        printf '%s\n' "$hits" | sed 's/^/     /'
        echo
        failed=1
    fi
}

scan_interpolation "${php_files[@]}" "${kotlin_files[@]}"

# ---------------------------------------------------------------- 结论

if [ "$failed" -ne 0 ]; then
    cat >&2 <<'EOF'
敏感日志扫描未通过（§13.3）。

出路，按优先级：
  1. 别记这条日志 —— 大多数情况下它只在开发时有用，而它会永远留在代码里
  2. 只记标识符（card.id / user.id），不记内容
  3. PHP 侧用注入的 LoggerInterface，Kotlin 侧用 Timber，并确认 §14.4 的
     Monolog 脱敏 processor 认得这个字段名

确有正当理由的例外，写进 code review 而不是加一条豁免 —— 这个脚本刻意没有
豁免表：一旦有了，它会变成绕开门禁的后门。
EOF
    exit 1
fi

echo "✓ 敏感日志扫描：PHP ${#php_files[@]} 个文件 / Kotlin ${#kotlin_files[@]} 个文件，0 命中"
