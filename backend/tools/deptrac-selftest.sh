#!/usr/bin/env bash
#
# T-002 验收标准：「故意写一个跨模块 Domain 引用能让 deptrac 报 violation」。
#
# 做成脚本而不是一次性手工验证，是因为这条断言的价值在**将来**：
# 谁把 deptrac.yaml 的规则改松了（比如给 *.Domain 加上别的模块），
# 这个脚本会立刻变红，而 `deptrac analyse` 依然是绿的 —— 它只能证明
# 「当前代码没违规」，证明不了「规则还有效」。
#
# 用法：composer deptrac:selftest
#
set -euo pipefail

cd "$(dirname "$0")/.."

VIOLATOR='src/Module/Wallet/Domain/__DeptracSelfTestViolation.php'
TARGET='src/Module/Identity/Domain/__DeptracSelfTestTarget.php'

cleanup() {
    rm -f "$VIOLATOR" "$TARGET"
}
trap cleanup EXIT

cleanup

cat > "$TARGET" <<'PHP'
<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

/** deptrac 自检临时文件，由 tools/deptrac-selftest.sh 写入并删除。 */
final class __DeptracSelfTestTarget
{
}
PHP

cat > "$VIOLATOR" <<'PHP'
<?php

declare(strict_types=1);

namespace App\Module\Wallet\Domain;

use App\Module\Identity\Domain\__DeptracSelfTestTarget;

/** deptrac 自检临时文件：Wallet.Domain 直接引用 Identity.Domain，必须被拦下。 */
final class __DeptracSelfTestViolation
{
    public function __construct(public readonly __DeptracSelfTestTarget $target)
    {
    }
}
PHP

set +e
OUTPUT="$(vendor/bin/deptrac analyse --no-progress --no-cache 2>&1)"
EXIT_CODE=$?
set -e

if [ "$EXIT_CODE" -eq 0 ]; then
    echo "$OUTPUT"
    echo
    echo "✗ deptrac 自检失败：跨模块 Domain 引用**没有**被拦下。"
    echo "  deptrac.yaml 的模块边界规则已失效，请检查 ruleset 里 *.Domain 的允许列表。"
    exit 1
fi

if ! grep -q 'Wallet\.Domain' <<<"$OUTPUT" || ! grep -q 'Identity\.Domain' <<<"$OUTPUT"; then
    echo "$OUTPUT"
    echo
    echo "✗ deptrac 自检失败：deptrac 报错了，但报的不是预期的"
    echo "  Wallet.Domain -> Identity.Domain violation。"
    exit 1
fi

echo "$OUTPUT" | grep -E 'Wallet|violation|Violation' || true
echo
echo "✓ deptrac 自检通过：跨模块 Domain 引用被拦下（Wallet.Domain -> Identity.Domain）。"
