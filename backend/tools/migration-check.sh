#!/usr/bin/env bash
#
# Doctrine 迁移检查（§13.3 后端表最后一行）：
#   `doctrine:schema:validate` 通过；迁移可 `up` + `down` 往返。
#
# 为什么 down 往返要进 CI：§13.5 规定「每个迁移必须有可用的 down()」，而一个从没被
# 执行过的 down() 就是一段没跑过的代码。真正需要它的时刻是**生产回滚**——
# 那时才发现它写错了，是这条规范能出的最坏结果。
#
# ⚠️ `backend/migrations/` 现在只有 .gitkeep（第一个迁移由 T-101 写），所以往返那两步
# 目前是空转。这是刻意的：门禁先就位，第一个迁移落地当天自动生效，而不是等到那天
# 才想起来还得搭一套。与 T-002 对覆盖率阈值「此刻还没代码，配置先就位」同一个做法。
#
# ⚠️ `doctrine:schema:validate` 现在**不存在** —— 它是 ORM 的命令，而
# config/packages/doctrine.yaml 只装了 DBAL（那份文件的注释写明 `orm:` 段属 T-101）。
# 本脚本探测到命令缺失时打印一行显式说明，**不静默跳过**：静默跳过的门禁与不存在的
# 门禁没有区别，而三个月后没人记得它为什么是绿的。
#
#   用法: backend/tools/migration-check.sh [--require-db]
#         composer migration:check
#
# 没有 --require-db 时，连不上数据库会**跳过**并退出 0 —— 与 tests/Integration 里
# 那些 markTestSkipped 的用例同一个口径（见 backend/.env.test 的注释）：
# 裸机上 `composer qa` 必须保持全绿，不能强迫每个人先起 compose 栈。
# CI 传 --require-db，那里数据库一定在（backend.yml 的 postgres service），
# 连不上就是真出了问题，必须红。
#
set -euo pipefail

cd "$(dirname "$0")/.."

REQUIRE_DB=0
if [ "${1:-}" = "--require-db" ]; then
    REQUIRE_DB=1
fi

console() { php bin/console --env=test "$@"; }

# ---------------------------------------------------------------- 前置：数据库

if ! console dbal:run-sql 'SELECT 1' >/dev/null 2>&1; then
    if [ "$REQUIRE_DB" -eq 1 ]; then
        cat >&2 <<'EOF'
!! 连不上测试数据库，而调用方传了 --require-db。

   CI 上这意味着 postgres service 没起来或 DATABASE_URL 不对 ——
   不是「跳过」，是真的出了问题。见 .github/workflows/backend.yml 的 services 段。
EOF
        exit 1
    fi
    echo "⊘ 跳过迁移检查：连不上测试数据库（起 compose 栈后重跑，或用 --require-db 强制失败）"
    exit 0
fi

# ---------------------------------------------------------------- ① up

echo "==> 迁移到最新"
console doctrine:migrations:migrate --no-interaction --allow-no-migration

# ---------------------------------------------------------------- ② down/up 往返

migration_count="$(find migrations -maxdepth 1 -name 'Version*.php' | wc -l)"

if [ "$migration_count" -eq 0 ]; then
    echo "⊘ 往返检查暂无对象：migrations/ 里还没有迁移文件（第一个由 T-101 写）"
else
    echo "==> 回滚全部 $migration_count 个迁移（验 down()）"
    console doctrine:migrations:migrate first --no-interaction

    echo "==> 再迁回最新（验 down() 之后 up() 仍然可用）"
    console doctrine:migrations:migrate --no-interaction
fi

# ---------------------------------------------------------------- ③ schema:validate

if console list doctrine 2>/dev/null | grep -q 'doctrine:schema:validate'; then
    echo "==> doctrine:schema:validate"
    console doctrine:schema:validate
else
    cat <<'EOF'
⊘ 跳过 doctrine:schema:validate：本项目暂未安装 Doctrine ORM。

   config/packages/doctrine.yaml 目前只有 dbal: 段 —— T-003 只需要一条能 SELECT 1
   的连接。装 ORM、补 orm: 段、补 mapping_types 的 uuid 映射都属于 T-101，
   那一步做完这里会自动开始跑（本脚本按命令是否存在判断，不需要改）。
EOF
fi

echo "✓ Doctrine 迁移检查通过"
