<?php

declare(strict_types=1);

namespace App\Shared\Application\Cleanup;

/**
 * 一条每日清理任务（§8.2 的保留期在生产上的执行点，T-113）。
 *
 * 实现类会被 config/services.yaml 的 `_instanceof` 自动打上 `app.cleanup_task` 标签，
 * 由 {@see CleanupRunner} 收集 —— 与 `Shared\Application\Seed\SeederInterface` 同一个
 * 扩展点形状。新增一条清理**只需实现这个接口**，不用回来改 runner、命令或 Schedule。
 *
 * 已知的后续实现（compose 的 scheduler 槽位注释里点过名）：
 *   T-204  change_log 90 天保留期
 *   T-402  过期的导出包与一次性下载令牌
 *   T-403  PurgeDeletedAccounts（§8.4 宽限期到期的硬删除）
 *   T-404  key rewrap
 *
 * ============================================================================
 * 两条实现约束
 * ============================================================================
 * 1. **必须幂等。** 同一天跑两次、或漏跑一天第二天补上，结果都必须正确。
 *    所以判据一律是「截止时刻」（`created_at < now - N`），不是增量游标 ——
 *    这也是 scheduler 敢跑单副本、且不配 `stateful()` 的前提（见 ADR-0022）。
 *
 * 2. **`$now` 由调用方传入，不要自己读时钟。** 一趟清理里所有任务共用**同一个**
 *    时刻，否则「删了 A 没删 B」会随两次时钟读取之间的毫秒数漂移；
 *    集成测试也正是靠注入一个固定的 `$now` 才钉得住第 7 天 vs 第 8 天的边界。
 */
interface CleanupTaskInterface
{
    /**
     * 任务名，惯例是 `<模块>.<对象>`（`identity.zombie_registrations`）。
     *
     * 它不只是给人看的：它是 `app:cleanup --task=` 的选择键，也是
     * `cleanup_rows_total{task}` 的标签值。所以**取值域必须闭合**
     * （§14.4：标签基数有界），不得把 id、邮箱之类拼进去。
     *
     * @return non-empty-string
     */
    public function name(): string;

    /**
     * 跑一次，返回**处理过的行数**（删掉的、或改写的）。
     *
     * 返回 0 是正常结果，不是失败 —— 一个没有任何过期数据的库本来就该返回 0。
     * 失败的表达方式是抛异常，由 {@see CleanupRunner} 逐个任务接住。
     *
     * @param \DateTimeImmutable $now 本趟清理的统一时刻（UTC，见接口注释第 2 条）
     *
     * @return int<0, max>
     */
    public function run(\DateTimeImmutable $now): int;
}
