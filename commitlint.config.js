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
        'deps',
        'release',
      ],
    ],
    'header-max-length': [2, 'always', 100],
    'body-max-line-length': [1, 'always', 100],
    'subject-case': [2, 'never', ['pascal-case', 'upper-case', 'start-case']],
  },
};
