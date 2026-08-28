#!/usr/bin/env bash
#
# deptrac 规则本身的自检：故意写违规代码，断言 deptrac 拦得下来。
#
# 做成脚本而不是一次性手工验证，是因为这条断言的价值在**将来**：
# 谁把 deptrac.yaml 的规则改松了，这个脚本会立刻变红，而 `deptrac analyse`
# 依然是绿的 —— 它只能证明「当前代码没违规」，证明不了「规则还有效」。
#
# 两个场景，对应 deptrac.yaml 同时强制的两个维度：
#
#   ① 模块间（T-002 验收标准）
#      Wallet.Domain 直接引用 Identity.Domain → 必须 violation。
#
#   ② 层间 · Shared.Domain 的空白名单（T-004 加）
#      Shared.Domain import Symfony\Component\HttpFoundation\Request → 必须 violation。
#      T-004 的 Uuid / Clock / ErrorCode 全部建立在「Shared.Domain: [] 可满足」之上，
#      而在此之前**没有任何东西**证明这条规则还有效 —— 场景 ① 只覆盖模块维度。
#      这条一旦失守，Domain 层会悄悄长出框架依赖，等发现时已经改不动了。
#
# 用法：composer deptrac:selftest
#
set -euo pipefail

cd "$(dirname "$0")/.."

MODULE_VIOLATOR='src/Module/Wallet/Domain/__DeptracSelfTestViolation.php'
MODULE_TARGET='src/Module/Identity/Domain/__DeptracSelfTestTarget.php'
LAYER_VIOLATOR='src/Shared/Domain/__DeptracSelfTestFrameworkImport.php'

cleanup() {
    rm -f "$MODULE_VIOLATOR" "$MODULE_TARGET" "$LAYER_VIOLATOR"
}
trap cleanup EXIT

cleanup

# 跑一次 deptrac，要求它**失败**，且输出里出现指定的两个图层名。
# $1 场景描述 / $2 期望图层 A / $3 期望图层 B / $4 失败时的排查提示
assert_violation() {
    local description="$1" layer_a="$2" layer_b="$3" hint="$4"
    local output exit_code

    set +e
    output="$(vendor/bin/deptrac analyse --no-progress --no-cache 2>&1)"
    exit_code=$?
    set -e

    if [ "$exit_code" -eq 0 ]; then
        echo "$output"
        echo
        echo "✗ deptrac 自检失败：${description}**没有**被拦下。"
        echo "  ${hint}"
        exit 1
    fi

    if ! grep -q "$layer_a" <<<"$output" || ! grep -q "$layer_b" <<<"$output"; then
        echo "$output"
        echo
        echo "✗ deptrac 自检失败：deptrac 报错了，但报的不是预期的"
        echo "  ${layer_a} -> ${layer_b} violation。"
        exit 1
    fi

    echo "✓ ${description}被拦下（${layer_a} -> ${layer_b}）。"
}

# ---------------------------------------------------------------- 场景 ①
cat > "$MODULE_TARGET" <<'PHP'
<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

/** deptrac 自检临时文件，由 tools/deptrac-selftest.sh 写入并删除。 */
final class __DeptracSelfTestTarget
{
}
PHP

cat > "$MODULE_VIOLATOR" <<'PHP'
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

assert_violation \
    '跨模块 Domain 引用' \
    'Wallet\.Domain' \
    'Identity\.Domain' \
    'deptrac.yaml 的模块边界规则已失效，请检查 ruleset 里 *.Domain 的允许列表。'

rm -f "$MODULE_VIOLATOR" "$MODULE_TARGET"

# ---------------------------------------------------------------- 场景 ②
cat > "$LAYER_VIOLATOR" <<'PHP'
<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Symfony\Component\HttpFoundation\Request;

/** deptrac 自检临时文件：Shared.Domain import 框架类型，必须被拦下（§12.2）。 */
final class __DeptracSelfTestFrameworkImport
{
    public function __construct(public readonly Request $request)
    {
    }
}
PHP

assert_violation \
    'Shared.Domain 的框架 import' \
    'Shared\.Domain' \
    'Framework\.Http' \
    'deptrac.yaml 的 `Shared.Domain: []` 已被放宽。T-004 的 Uuid/Clock/ErrorCode 全部依赖这条规则 —— 见 src/Shared/Domain/Identity/Uuid.php 的类注释。'

echo
echo "✓ deptrac 自检全部通过（模块边界 + Shared.Domain 空白名单）。"
