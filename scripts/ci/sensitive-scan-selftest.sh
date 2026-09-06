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

# .gitleaks.toml 有两条 regex 豁免和若干条按文件的豁免。regex 豁免的风险是写宽了，
# 按文件的豁免的风险是把整份文件放行 —— 下面每条按文件的豁免都配一对断言守这件事：
# 「换个文件还拦不拦」+「同一个文件里别的规则还生效不生效」。
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

    # T-012 的 secrets.sops.yaml 豁免同理：只放行 generic-api-key（密文的高熵串），
    # 强特征规则照常生效。这一条守的是「有人把这条豁免改成整份文件放行」。
    d="$(new_repo gitleaks-sops)"
    mkdir -p "$d/infra/ansible/inventory/group_vars/ncards_staging"
    cp "$SCRIPT_DIR/../../.gitleaks.toml" "$d/"
    # 键名用 x-note 而不是 AWS_KEY：本文件对 aws-access-token 有豁免（见 .gitleaks.toml），
    # 但 `AWS_KEY: <高熵串>` 这种「关键词 + 赋值」的形态会另外触发 generic-api-key，
    # 而那条规则在本文件里**没有**豁免 —— 于是夹具本身会让门禁红。
    # 上面 openapi 那条夹具用的也是 x-note，同样的理由。
    printf 'x-note: AKIAZZ4H7XKQ2JVNPLQR\nsops:\n    version: 3.9.0\n' \
        > "$d/infra/ansible/inventory/group_vars/ncards_staging/secrets.sops.yaml"
    seal "$d"
    assert 1 "$SCRIPT_DIR/check-gitleaks.sh" "$d" "被豁免的 secrets.sops.yaml 里的 AWS key 仍被拦下"

    # T-104 的 VaultKvSigningKeyProviderTest.php 豁免了 private-key（夹具是一把
    # 只存在于那个文件里的 openssl 测试密钥）。这一条守的是「豁免没扩散到别处」——
    # 同样的私钥块出现在任何别的文件里都必须红。
    d="$(new_repo gitleaks-signing-key)"
    mkdir -p "$d/backend/src"
    cp "$SCRIPT_DIR/../../.gitleaks.toml" "$d/"
    printf -- '-----BEGIN PRIVATE KEY-----\nMC4CAQAwBQYDK2VwBCIEIF8jlXsvE6MwCvuPU6n9qCKd7qqMn73PBnA11jfQI4je\n-----END PRIVATE KEY-----\n' \
        > "$d/backend/src/Signing.php"
    seal "$d"
    assert 1 "$SCRIPT_DIR/check-gitleaks.sh" "$d" "私钥块出现在测试夹具以外的文件里被拦下"

    # 反向：被豁免的那个文件里，private-key 之外的规则照常生效。
    # 防的是有人把 targetRules 去掉、变成整份文件放行。
    d="$(new_repo gitleaks-signing-key-scope)"
    mkdir -p "$d/backend/tests/Unit/Shared/Infrastructure/Token"
    cp "$SCRIPT_DIR/../../.gitleaks.toml" "$d/"
    printf '<?php\n$k = "AKIAZZ4H7XKQ2JVNPLQR";\n' \
        > "$d/backend/tests/Unit/Shared/Infrastructure/Token/VaultKvSigningKeyProviderTest.php"
    seal "$d"
    assert 1 "$SCRIPT_DIR/check-gitleaks.sh" "$d" "被豁免的密钥测试文件里的 AWS key 仍被拦下"
fi

echo
echo "== sops 加密检查 =="

# gitleaks 对 secrets.sops.yaml 豁免了 generic-api-key，于是「忘了加密就提交」
# **不会**被 gitleaks 拦下 —— 挡它的是 check-sops-encrypted.sh。
# 这两条断言证明那条豁免没有变成一个后门。
SOPS_SCANNER="$SCRIPT_DIR/check-sops-encrypted.sh"

# ⑧ 明文提交的 secrets.sops.yaml
d="$(new_repo sops-plain)"
mkdir -p "$d/infra/ansible/inventory/group_vars/ncards_staging"
# 夹具值刻意用 dev-only- 前缀（仓库的占位值约定，见 .gitleaks.toml）。
# 这里**不需要**真·凭据形态 —— 与上面那几条 gitleaks 断言不同，
# check-sops-encrypted.sh 的判据只有一条「值是不是以 ENC[ 开头」，与熵无关。
# 放一个高熵串反而会触发 generic-api-key，让这个夹具自己把门禁弄红。
cat > "$d/infra/ansible/inventory/group_vars/ncards_staging/secrets.sops.yaml" <<'YAML'
APP_SECRET: dev-only-forgot-to-run-sops-encrypt
POSTGRES_PASSWORD: dev-only-still-plaintext
sops:
    version: 3.9.0
YAML
seal "$d"
assert 1 "$SOPS_SCANNER" "$d" "未加密的 secrets.sops.yaml 被拦下"

# ⑨ 真加密过的放行
d="$(new_repo sops-encrypted)"
mkdir -p "$d/infra/ansible/inventory/group_vars/ncards_staging"
cat > "$d/infra/ansible/inventory/group_vars/ncards_staging/secrets.sops.yaml" <<'YAML'
APP_SECRET: ENC[AES256_GCM,data:Kx8fQ==,iv:9bT=,tag:mA==,type:str]
POSTGRES_PASSWORD: ENC[AES256_GCM,data:Lp2wR==,iv:7cU=,tag:nB==,type:str]
sops:
    age:
        - recipient: age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p
          enc: |
            -----BEGIN AGE ENCRYPTED FILE-----
            -----END AGE ENCRYPTED FILE-----
    lastmodified: "2026-09-02T00:00:00Z"
    version: 3.9.0
YAML
seal "$d"
assert 0 "$SOPS_SCANNER" "$d" "已加密的 secrets.sops.yaml 放行"

# ⑩ 明文模板不在检查范围内（*.sops.yaml.example 是给人看的）
d="$(new_repo sops-example)"
mkdir -p "$d/infra/ansible/inventory/group_vars/ncards_staging"
printf 'APP_SECRET: dev-only-replace-me\n' \
    > "$d/infra/ansible/inventory/group_vars/ncards_staging/secrets.sops.yaml.example"
seal "$d"
assert 0 "$SOPS_SCANNER" "$d" "明文模板 .example 不误报"

echo
if [ "$failures" -ne 0 ]; then
    echo "!! 自检未通过 —— 扫描器的规则被改坏了，或者夹具需要跟着更新" >&2
    exit 1
fi
echo "✓ 全部断言通过"
