#!/usr/bin/env bash
#
# deptrac 规则本身的自检：故意写违规代码，断言 deptrac 拦得下来。
#
# 做成脚本而不是一次性手工验证，是因为这条断言的价值在**将来**：
# 谁把 deptrac.yaml 的规则改松了，这个脚本会立刻变红，而 `deptrac analyse`
# 依然是绿的 —— 它只能证明「当前代码没违规」，证明不了「规则还有效」。
#
# 四个场景。前两个对应 deptrac.yaml 同时强制的两个维度（模块间 / 层间），
# 后两个各自守着一条「某个设计决定的唯一强制点」：
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
#   ③ 加密门面只经接口暴露（T-005 验收标准）
#      Wallet.Application import Shared\Infrastructure\Crypto\VaultTransitCrypto
#      → 必须 violation。
#      T-005 的验收标准原文是「deptrac 确认无模块直接 import VaultTransitCrypto
#      （只经接口）」。这一条**今天自动成立** —— Shared.Infrastructure 不在任何模块的
#      允许列表里 —— 恰恰因为如此才需要这个自检：`deptrac analyse` 全绿只能说明
#      「当前没人这么写」，说明不了「这么写会被拦下」。谁哪天往某个模块的允许列表里
#      加了 Shared.Infrastructure（比如为了图省事直接注入某个 Infrastructure 服务），
#      §5.3 的门面就被架空了，而 CI 不会有任何反应。
#
#   ④ 发信只经 MailSenderInterface（T-102）
#      Identity.Application import Symfony\Component\Mailer\MailerInterface
#      → 必须 violation。
#      §3.2 把「一期单通道、无双活」记为有意识的风险接受，代价是那句
#      「未来 2 人日能补回来」——而它成立的**唯一**前提是「邮箱服务商的细节
#      完全收敛在 MailSenderInterface 之后」。强制点是 deptrac 的
#      `Framework.Mail` 图层只加进了 Notification.Infrastructure 的允许列表。
#      谁哪天把它补进别的 *.Infrastructure（或者更糟：忘了在 Framework.Core 的
#      must_not 里排除 Mailer，于是它落回那个对所有 Application 开放的图层），
#      §3.2 的前提就没了，而 `deptrac analyse` 依然全绿。
#
# 用法：composer deptrac:selftest
#
set -euo pipefail

cd "$(dirname "$0")/.."

MODULE_VIOLATOR='src/Module/Wallet/Domain/__DeptracSelfTestViolation.php'
MODULE_TARGET='src/Module/Identity/Domain/__DeptracSelfTestTarget.php'
LAYER_VIOLATOR='src/Shared/Domain/__DeptracSelfTestFrameworkImport.php'
CRYPTO_VIOLATOR='src/Module/Wallet/Application/__DeptracSelfTestCryptoImport.php'
MAILER_VIOLATOR='src/Module/Identity/Application/__DeptracSelfTestMailerImport.php'

cleanup() {
    rm -f "$MODULE_VIOLATOR" "$MODULE_TARGET" "$LAYER_VIOLATOR" "$CRYPTO_VIOLATOR" "$MAILER_VIOLATOR"
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

rm -f "$LAYER_VIOLATOR"

# ---------------------------------------------------------------- 场景 ③
# 引用的是**真实**的 VaultTransitCrypto，不是临时靶子 —— 验收标准点了这个类的名，
# 自检就照着它写。将来若该类被改名或搬走，这里会因为找不到类而失败，
# 那正是「有人动了加密门面的结构，请重新确认边界」该有的信号。
cat > "$CRYPTO_VIOLATOR" <<'PHP'
<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application;

use App\Shared\Infrastructure\Crypto\VaultTransitCrypto;

/** deptrac 自检临时文件：模块直接 import 加密实现（而非接口），必须被拦下（T-005）。 */
final class __DeptracSelfTestCryptoImport
{
    public function __construct(public readonly VaultTransitCrypto $crypto)
    {
    }
}
PHP

assert_violation \
    '模块直接 import VaultTransitCrypto' \
    'Wallet\.Application' \
    'Shared\.Infrastructure' \
    'deptrac.yaml 里某个模块的 Application 允许列表被加进了 Shared.Infrastructure。T-005 的验收标准「无模块直接 import VaultTransitCrypto」就此失效 —— 加解密必须只经 Shared\Application\Crypto 的三个接口，见 CryptoServiceInterface 的类注释。'

rm -f "$CRYPTO_VIOLATOR"

# ---------------------------------------------------------------- 场景 ④
# 用 Identity.Application 当违规者不是随手挑的：它就是 T-103/T-104 里真正会想
# 「发封信」的那一层。这条自检要拦的正是那个最自然的错误写法。
cat > "$MAILER_VIOLATOR" <<'PHP'
<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use Symfony\Component\Mailer\MailerInterface;

/** deptrac 自检临时文件：模块绕过 MailSenderInterface 直接发信，必须被拦下（T-102）。 */
final class __DeptracSelfTestMailerImport
{
    public function __construct(public readonly MailerInterface $mailer)
    {
    }
}
PHP

assert_violation \
    '模块绕过 MailSenderInterface 直接 import Mailer' \
    'Identity\.Application' \
    'Framework\.Mail' \
    'deptrac.yaml 的 Framework.Mail 图层被放宽了（加进了别的允许列表），或者 Framework.Core 的 must_not 里少了对应的排除条目 —— 后者会让 MailerInterface 落回那个对所有 Application 开放的图层。§3.2「把服务商细节收敛在 MailSenderInterface 之后」就此失效，见 MailSenderInterface 的类注释与 ADR-0012。'

rm -f "$MAILER_VIOLATOR"

echo
echo "✓ deptrac 自检全部通过（模块边界 + Shared.Domain 空白名单 + 加密门面只经接口 + 发信只经 MailSenderInterface）。"
