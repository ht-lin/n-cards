<?php

declare(strict_types=1);

namespace App\Tests\Double\Transaction;

use App\Shared\Application\Transaction\TransactionRunnerInterface;

/**
 * 直接执行闭包的事务替身，**并记下它被用过没有**。
 *
 * ============================================================================
 * 为什么「被用过没有」值得断言
 * ============================================================================
 * 真正的回滚语义只有真 Postgres 能证明（那条在
 * `tests/Integration/Shared/Doctrine/TransactionalRunnerTest`）。
 * 单测这一层能证明的是另一件事，而它同样是不变量：
 * **登录的三步写入确实被包在一个事务里**。
 *
 * 没有这条断言，谁把 `VerifyOtpService` 里的 `run()` 拆掉、让 user / device /
 * session 各自 flush，所有功能用例仍然全绿 —— 而生产上会开始出现
 * 「建了用户但没有会话」这种要人工修的中间态，且只在并发或 Vault 抖动时出现。
 *
 * ⚠️ 这个替身**不模拟回滚**：闭包抛异常时它原样向上抛，已经发生的写入留在
 * 各个 in-memory 仓储里。用它去断言「失败后什么都没留下」会得到假红。
 */
final class RecordingTransactionRunner implements TransactionRunnerInterface
{
    private int $runs = 0;

    private bool $inTransaction = false;

    public function run(\Closure $operation): mixed
    {
        ++$this->runs;

        $outer = $this->inTransaction;
        $this->inTransaction = true;

        try {
            return $operation();
        } finally {
            $this->inTransaction = $outer;
        }
    }

    public function isInTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function runs(): int
    {
        return $this->runs;
    }
}
