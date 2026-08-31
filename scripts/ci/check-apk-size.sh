#!/usr/bin/env bash
#
# APK 大小回归门禁（§13.3 Android 表倒数第二行）：
#   `assembleRelease` 成功，且 APK 大小回归 ≤ +500 KB（超出需 PR 说明）。
#
# 为什么值得一条门禁：§9.1 给了「APK ≤ 20 MB」的预算，而体积是**一点一点**涨没的。
# 每个 PR 单看都只多了几十 KB，没有哪一个该被拦下 —— 拦得住的只有一条基线。
#
# 基线文件 android/app/apk-size-baseline.txt 是**入库的**，涨了就在同一个 PR 里
# 更新它。这正是规格书那句「超出需 PR 说明」的落地形态：数字的变化会出现在 diff 里，
# reviewer 必须看见它，而不是在某次发版时才发现包大了 3 MB。
#
#   用法: scripts/ci/check-apk-size.sh [APK 路径] [基线文件]
#   默认: android/app/build/outputs/apk/release/app-release-unsigned.apk
#         android/app/apk-size-baseline.txt
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
APK="${1:-$ROOT/android/app/build/outputs/apk/release/app-release-unsigned.apk}"
BASELINE_FILE="${2:-$ROOT/android/app/apk-size-baseline.txt}"

# §13.3 原文：≤ +500 KB。这里按 KiB 算（500 * 1024）。
readonly TOLERANCE=512000

if [ ! -f "$APK" ]; then
    echo "!! 找不到 APK：$APK" >&2
    echo "   先跑：cd android && ./gradlew :app:assembleRelease" >&2
    exit 1
fi

if [ ! -f "$BASELINE_FILE" ]; then
    echo "!! 找不到基线文件：$BASELINE_FILE" >&2
    exit 1
fi

baseline="$(grep -vE '^[[:space:]]*(#|$)' "$BASELINE_FILE" | head -1 | tr -d '[:space:]')"
if ! [[ "$baseline" =~ ^[0-9]+$ ]]; then
    echo "!! 基线文件的第一行有效内容必须是一个字节数，读到的是：'$baseline'" >&2
    exit 1
fi

actual="$(stat -c '%s' "$APK")"
delta=$(( actual - baseline ))

# LC_NUMERIC=C：德语 locale 下 awk 会把小数点打成逗号，而这一行会被人复制进
# issue 与 PR 说明里。数字的格式不该取决于谁的机器跑的。
mib() { LC_NUMERIC=C awk -v b="$1" 'BEGIN { printf "%.2f MiB", b / 1048576 }'; }

printf '   基线 %12s 字节（%s）\n' "$baseline" "$(mib "$baseline")"
printf '   实际 %12s 字节（%s）\n' "$actual" "$(mib "$actual")"
printf '   变化 %12s 字节\n' "$delta"

if [ "$delta" -gt "$TOLERANCE" ]; then
    cat >&2 <<EOF

!! APK 比基线大了 $delta 字节，超过 §13.3 允许的 $TOLERANCE（500 KiB）。

   先弄清楚多出来的是什么 —— 多半是一个新依赖，而不是自己写的代码：
     cd android && ./gradlew :app:assembleRelease
     unzip -l app/build/outputs/apk/release/app-release-unsigned.apk | sort -k1 -n | tail -30

   确实该涨（例如引入了一个必需的库），就在**同一个 PR 里**更新基线：
     echo $actual > $(realpath --relative-to="$ROOT" "$BASELINE_FILE")
   并在 PR 说明里写清多出来的是什么、为什么必须要 —— 这是 §13.3 的原文要求。
EOF
    exit 1
fi

# 缩小不拦，但要提醒：基线放着不更新，下一个人就等于多了一份额度可以花。
if [ "$delta" -lt "-$TOLERANCE" ]; then
    echo "   ⚠ 比基线小了不少。顺手把基线更新成 $actual，否则这份额度会被后面的 PR 悄悄吃掉。"
fi

echo "✓ APK 大小回归在 §13.3 的预算内"
