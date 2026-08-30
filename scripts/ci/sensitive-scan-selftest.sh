#!/usr/bin/env bash
#
# 两个通用扫描器本身的自检：造正反例，断言它们真的会咬人。
#
# 与 backend/tools/deptrac-selftest.sh、android/tools/module-graph-selftest.sh 同一个
# 思路：`check-sensitive-logs.sh` 在当前仓库上全绿，只能说明「现在没人违规」，
# 说明不了「违规会被拦下」。谁哪天把一条正则改松了，扫描依然是绿的。
#
# **这条脚本就是 T-011 验收标准第二条的落地** ——「故意提交一个含 `Log.d(card)` 的
# diff 会被阻断」。场景 ② 逐字用的就是那个例子。
#
# 反例同样重要：场景 ⑤ 守的是「注释行不算违规」。不守这一条，第一次跑就会红在
# android/app/.../NcardsApplication.kt 的 KDoc 上 —— 那里逐字写着 `Timber.d(card)`，
# 讲的正是「Timber 在生产上是空操作」。一个误报自己人的门禁活不过两周。
#
#   用法: scripts/ci/sensitive-scan-selftest.sh
#
# 依赖: git、mktemp
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG_SCANNER="$SCRIPT_DIR/check-sensitive-logs.sh"
TODO_SCANNER="$SCRIPT_DIR/check-todo-issue-refs.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

failures=0

# 夹具仓库。扫描器的文件清单取自 `git ls-files`，所以 `git add` 不能省 —— 但不需要
# commit（ls-files 读的是索引），也就不需要在 CI 上配 user.name / user.email。
new_repo() {
    local dir="$WORK/$1"
    mkdir -p "$dir"
    git -C "$dir" init -q
    printf '%s' "$dir"
}

seal() { git -C "$1" add -A; }

# assert <期望退出码 0|1> <扫描器> <夹具目录> <场景说明>
assert() {
    local want="$1" scanner="$2" dir="$3" desc="$4"
    local out rc
    set +e
    out="$("$scanner" "$dir" 2>&1)"
    rc=$?
    set -e

    if [ "$rc" -eq "$want" ]; then
        printf '  ✓ %s\n' "$desc"
    else
        printf '  ✗ %s —— 期望退出码 %s，实际 %s\n' "$desc" "$want" "$rc"
        printf '%s\n' "$out" | sed 's/^/       /'
        failures=1
    fi
}

echo "== 敏感日志扫描 =="

# ① PHP 调试输出
d="$(new_repo php-dump)"
mkdir -p "$d/backend/src"
cat > "$d/backend/src/CardService.php" <<'PHP'
<?php
final class CardService
{
    public function save(Card $card): void
    {
        var_dump($card);
    }
}
PHP
seal "$d"
assert 1 "$LOG_SCANNER" "$d" "backend/src 里的 var_dump( 被拦下"

# ② 验收标准原文的那一条
d="$(new_repo kotlin-log)"
mkdir -p "$d/android/app/src/main/kotlin"
cat > "$d/android/app/src/main/kotlin/WalletViewModel.kt" <<'KT'
package de.ncards

class WalletViewModel {
    fun onCardLoaded(card: Card) {
        Log.d("wallet", card.toString())
    }
}
KT
seal "$d"
assert 1 "$LOG_SCANNER" "$d" "Log.d(card) 被拦下（T-011 验收标准第二条）"

# ③ 敏感字段插值进日志
d="$(new_repo interpolation)"
mkdir -p "$d/android/core/crypto/src/main/kotlin" "$d/backend/src"
cat > "$d/android/core/crypto/src/main/kotlin/Login.kt" <<'KT'
package de.ncards

class Login {
    fun start(email: String) {
        Timber.i("发送 OTP 到 $email")
    }
}
KT
cat > "$d/backend/src/OtpHandler.php" <<'PHP'
<?php
final class OtpHandler
{
    public function handle(string $barcodeValue): void
    {
        $this->logger->info('scanned '.$barcodeValue);
    }
}
PHP
seal "$d"
assert 1 "$LOG_SCANNER" "$d" "email / barcodeValue 插值进日志被拦下"

# ④ 全部合规
d="$(new_repo clean)"
mkdir -p "$d/android/app/src/main/kotlin" "$d/backend/src"
cat > "$d/android/app/src/main/kotlin/WalletViewModel.kt" <<'KT'
package de.ncards

class WalletViewModel {
    fun onCardLoaded(card: Card) {
        Timber.d("card loaded: %s", card.id)
    }
}
KT
cat > "$d/backend/src/OtpHandler.php" <<'PHP'
<?php
final class OtpHandler
{
    public function handle(string $userId): void
    {
        $this->logger->info('otp requested', ['user_id' => $userId]);
    }
}
PHP
seal "$d"
assert 0 "$LOG_SCANNER" "$d" "只记标识符的日志放行"

# ⑤ 注释里的反例不算违规（NcardsApplication.kt 的 KDoc 回归）
d="$(new_repo comments)"
mkdir -p "$d/android/app/src/main/kotlin" "$d/backend/src"
cat > "$d/android/app/src/main/kotlin/NcardsApplication.kt" <<'KT'
package de.ncards

/**
 * release 里没有种任何 Tree，于是 `Timber.d(card)` 在生产上是彻底的空操作。
 * 推论：**不要**用 android.util.Log 绕过 Timber。
 */
class NcardsApplication {
    // Log.d("x", email) —— 这一行是反例，不是代码
    fun onCreate() = Unit
}
KT
cat > "$d/backend/src/Doc.php" <<'PHP'
<?php
// 禁止 var_dump($card) —— 这句是规范本身，不是违规
final class Doc
{
}
PHP
seal "$d"
assert 0 "$LOG_SCANNER" "$d" "注释行里的反例不误报"

echo
echo "== TODO 检查 =="

# ⑥ 无编号的 TODO
d="$(new_repo todo-bare)"
mkdir -p "$d/backend/src"
printf '<?php\n// TODO: 这里要处理 409\n' > "$d/backend/src/Sync.php"
seal "$d"
assert 1 "$TODO_SCANNER" "$d" "没有 issue 编号的 TODO 被拦下"

# ⑦ 有编号的 TODO + 中文裸词
d="$(new_repo todo-ok)"
mkdir -p "$d/backend/src"
printf '<?php\n// TODO(#123): 这里要处理 409\n' > "$d/backend/src/Sync.php"
printf '# 「TODO 必须带 issue 编号」由通用扫描负责，这里不重复实现\n' > "$d/detekt.yml"
seal "$d"
assert 0 "$TODO_SCANNER" "$d" "TODO(#123) 放行，中文裸词不误报"

echo
echo "== gitleaks 豁免的边界 =="

# .gitleaks.toml 有两条 regex 豁免和一条按文件的豁免。regex 豁免的风险是写宽了，
# 按文件的豁免的风险是把整份文件放行 —— 下面两条断言分别守这两件事。
#
# ⚠️ 夹具里要放**真·凭据形态**（AWS access key ID），不能放 dev-only- 那种占位值：
# 后者本来就在豁免表里，用它做夹具等于什么都没断言。
if ! command -v gitleaks >/dev/null 2>&1; then
    echo "  ⚠ 跳过：本机没有 gitleaks。CI 上 shared.yml 会先装再跑，不会跳过。"
    echo "     本地想跑：见 scripts/ci/check-gitleaks.sh 头部的安装提示。"
else
    d="$(new_repo gitleaks-plain)"
    mkdir -p "$d/backend/src"
    cp "$SCRIPT_DIR/../../.gitleaks.toml" "$d/"
    printf 'aws_access_key_id = AKIAZZ4H7XKQ2JVNPLQR\n' > "$d/backend/src/Bad.php"
    seal "$d"
    assert 1 "$SCRIPT_DIR/check-gitleaks.sh" "$d" "普通文件里的 AWS key 被拦下"

    # docs/api/openapi.yaml 只豁免了 generic-api-key 这一条规则（targetRules）。
    # 整份文件放行的写法在这一条上会变绿 —— 那正是要防的。
    d="$(new_repo gitleaks-openapi)"
    mkdir -p "$d/docs/api"
    cp "$SCRIPT_DIR/../../.gitleaks.toml" "$d/"
    printf 'openapi: 3.1.0\nx-note: AKIAZZ4H7XKQ2JVNPLQR\n' > "$d/docs/api/openapi.yaml"
    seal "$d"
    assert 1 "$SCRIPT_DIR/check-gitleaks.sh" "$d" "被豁免的 openapi.yaml 里的 AWS key 仍被拦下"
fi

echo
if [ "$failures" -ne 0 ]; then
    echo "!! 自检未通过 —— 扫描器的规则被改坏了，或者夹具需要跟着更新" >&2
    exit 1
fi
echo "✓ 全部断言通过"
