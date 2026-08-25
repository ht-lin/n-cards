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

# required status checks：当前只有 T-001 的 commitlint。
# T-011 交付三条流水线后在此追加 backend / android / shared。
REQUIRED_CHECKS='[{"context": "commitlint"}]'

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

existing_id="$(gh api "repos/$REPO/rulesets" --jq ".[] | select(.name == \"$RULESET_NAME\") | .id" 2>/dev/null || true)"

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
