/**
 * Conventional Commits 校验（§13.2）。
 * 在两处强制执行：本地 .husky/commit-msg 钩子 + CI（.github/workflows/commit-conventions.yml）。
 *
 * scope 取自 §12.2 后端模块与 §12.3 Android 结构。跨多个 scope 的改动省略 scope 即可。
 */
export default {
  extends: ['@commitlint/config-conventional'],
  rules: {
    'scope-enum': [
      2,
      'always',
      [
        // 后端模块（§12.2）
        'identity',
        'wallet',
        'sharing',
        'social',
        'sync',
        'notification',
        'compliance',
        'shared',
        // 端
        'backend',
        'android',
        // 横切
        'api',
        'infra',
        'ci',
        'docs',
        // T-005 补：docs/TECHNICAL_SPEC.md 的页脚要求「变更本文档需提 PR，
        // 标题以 `docs(spec):` 开头」，而这里原先没有 `spec`，于是那条规矩
        // 一旦被真的照做，PR 标题校验就会失败（本 workflow 也校验 PR 标题，
        // 因为 squash merge 之后它就是 main 上的 commit message）。
        'spec',
        'deps',
        'release',
      ],
    ],
    'header-max-length': [2, 'always', 100],
    'body-max-line-length': [1, 'always', 100],
    'subject-case': [2, 'never', ['pascal-case', 'upper-case', 'start-case']],
  },
};
