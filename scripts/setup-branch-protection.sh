#!/usr/bin/env bash
#
# 配置 main 分支保护（§13.2），用 GitHub ruleset 实现。
#
# 规则写成脚本而不是点 UI —— 规则本身才可 review、可复现、可 code review。
# 幂等：按名字查找已有 ruleset，存在则 PUT 更新，不存在则 POST 创建。
#
#   用法: scripts/setup-branch-protection.sh [owner/repo]
#   默认: 从 origin remote 推断
#
# 依赖: gh (已登录且有 repo admin 权限), jq
#
# ⚠️ bypass_actors 中的 RepositoryRole 5 = admin，是**单人期的临时豁免**：
#    没有它，单人账号无法批准自己的 PR，main 会锁死。
#    团队到 2 人时把 BYPASS_ACTORS 改为 '[]' 并重跑本脚本。

set -euo pipefail

REPO="${1:-}"
if [[ -z "$REPO" ]]; then
  REPO="$(gh repo view --json nameWithOwner --jq .nameWithOwner)"
fi

RULESET_NAME="main-protection"

# 单人期临时豁免，团队扩充后改为 []
BYPASS_ACTORS='[{"actor_id": 5, "actor_type": "RepositoryRole", "bypass_mode": "always"}]'

# required status checks：**只有 pr-gate 这一个**，别往里加第二个。
#
# ⚠️ 这不是偷懒，是这套拓扑唯一正确的配法（T-011 / ADR-0008）。
# backend / android / android-release 三条 job 都是**有条件运行**的
# （.github/workflows/pr.yml 的 changes 过滤器决定），而 GitHub 对不满足条件的 job
# **根本不上报 context**，不是上报一个「跳过」。把它们配成 required，再叠上
# 下面的 strict_required_status_checks_policy，只改 README 的 PR 会永远等一个
# 不会到来的 check —— 那是一把没有钥匙的锁。
#
# pr-gate 总是运行，needs 上那四条，并把 skipped 正确地算作通过。
# 新增一条流水线时要改的是 pr.yml 里 pr-gate 的 needs，**不是**这一行。
#
# （T-001 时期的 `commitlint` context 已经不存在了：commitlint 现在是
# shared 流水线里的两步，见 .github/workflows/shared.yml。）
REQUIRED_CHECKS='[{"context": "pr-gate"}]'

echo "==> 目标仓库: $REPO"

payload="$(jq -n \
  --arg name "$RULESET_NAME" \
  --argjson bypass "$BYPASS_ACTORS" \
  --argjson checks "$REQUIRED_CHECKS" \
  '{
    name: $name,
    target: "branch",
    enforcement: "active",
    bypass_actors: $bypass,
    conditions: {
      ref_name: { include: ["~DEFAULT_BRANCH"], exclude: [] }
    },
    rules: [
      { type: "deletion" },
      { type: "non_fast_forward" },
      { type: "required_linear_history" },
      {
        type: "pull_request",
        parameters: {
          required_approving_review_count: 1,
          dismiss_stale_reviews_on_push: true,
          require_code_owner_review: false,
          require_last_push_approval: false,
          required_review_thread_resolution: true,
          allowed_merge_methods: ["squash"]
        }
      },
      {
        type: "required_status_checks",
        parameters: {
          strict_required_status_checks_policy: true,
          do_not_enforce_on_create: false,
          required_status_checks: $checks
        }
      }
    ]
  }')"

if ! rulesets_json="$(gh api "repos/$REPO/rulesets" 2>&1)"; then
  echo "!! 无法读取 ruleset 列表：" >&2
  echo "   $rulesets_json" >&2
  echo >&2
  if [[ "$rulesets_json" == *"Upgrade to GitHub Pro"* ]]; then
    cat >&2 <<'EOF'
   原因：Free 套餐的**私有**仓库不支持 ruleset / branch protection。
   三条出路（由仓库所有者决定，本脚本不擅自选择）：
     1. 升级到 GitHub Pro（$4/月）—— 规则原样生效，推荐
     2. 把仓库改为 public —— 规格书与业务细节将对外可见
     3. 暂不启用服务端保护 —— 规则仅靠 CONTRIBUTING.md 与本地钩子约束，
        属于君子协定，团队扩充前风险可控但必须显式记录为已接受风险
EOF
  fi
  exit 1
fi

existing_id="$(printf '%s' "$rulesets_json" | jq -r ".[] | select(.name == \"$RULESET_NAME\") | .id")"

if [[ -n "$existing_id" ]]; then
  echo "==> 已存在 ruleset #$existing_id，更新中"
  printf '%s' "$payload" | gh api --method PUT "repos/$REPO/rulesets/$existing_id" --input - >/dev/null
else
  echo "==> 创建 ruleset"
  printf '%s' "$payload" | gh api --method POST "repos/$REPO/rulesets" --input - >/dev/null
fi

echo "==> 当前生效规则:"
gh api "repos/$REPO/rulesets" \
  --jq '.[] | "  \(.name)  [\(.enforcement)]  target=\(.target)"'

ruleset_id="${existing_id:-$(gh api "repos/$REPO/rulesets" --jq ".[] | select(.name == \"$RULESET_NAME\") | .id")}"
gh api "repos/$REPO/rulesets/$ruleset_id" --jq '.rules[] | "  - \(.type)"'

echo
echo "✓ main 分支保护已配置：禁直推 / 禁 force push / 禁删分支 / 线性历史 / 1 approve / CI 全绿"
echo "  ⚠️ repository admin 当前可 bypass（单人期临时措施，见 CONTRIBUTING.md §3）"
