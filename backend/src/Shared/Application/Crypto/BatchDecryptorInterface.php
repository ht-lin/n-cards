<?php

declare(strict_types=1);

namespace App\Shared\Application\Crypto;

use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\CryptoUnavailable;

/**
 * §5.3 的**硬要求**：一次请求解一批，禁止在循环里逐条调 Vault。
 *
 * ============================================================================
 * 这不是优化，是能不能上线的问题
 * ============================================================================
 * §9.1 的性能预算里写着「Vault batch decrypt（200 条）P95 ≤ 80 ms」，
 * §9.1 同时要求「`GET /v1/sync/bootstrap`（200 张卡）P95 ≤ 700 ms」（T-203）。
 *
 * 逐条调的话，200 张卡就是 200 次 HTTP 往返。即便同主机容器内网单次只要 2 ms，
 * 也是 400 ms 花在往返上，把 bootstrap 的整个预算吃掉大半；而真实的单次往返
 * （建连 + Vault 内部审计日志写盘）远不止 2 ms。批量接口把它压成 1 次。
 *
 * 所以这个接口存在的目的之一是**让正确的写法比错误的写法更顺手**：
 * 需要解一批时手边就有一个收数组的方法，不必自己写循环。
 * 强制点在 `tests/Unit/Shared/Infrastructure/Crypto/VaultBatchDecryptorTest` ——
 * 它断言 N 条只发**一次**请求，谁把实现改回循环，那条用例立刻红。
 *
 * ============================================================================
 * 键关联由调用方决定
 * ============================================================================
 * 入参与出参的键一一对应，调用方通常传 `[cardId => Ciphertext]` 拿回
 * `[cardId => plaintext]`，不用自己按下标对齐 —— 按下标对齐是这类批量 API 最经典的
 * 错位 bug 来源（一条失败、数组被 array_filter 压缩，后面全部错位，
 * 于是 A 的卡号显示成了 B 的）。
 *
 * ============================================================================
 * 部分失败：整批抛，不返回部分结果
 * ============================================================================
 * Vault 的 `batch_input` 是**逐条**给结果的，`batch_results[i].error` 非空表示这一条
 * 没解开（密文损坏、key 版本被 `min_decryption_version` 挡掉）。所以「返回解开的那些、
 * 静默丢掉失败的那些」在技术上做得到。这里刻意不那么做：
 *
 *   - 调用方拿到一个比入参短的数组，几乎必然不会去比对长度。于是钱包列表会**少一张卡**，
 *     而用户看到的不是错误提示，是「我的卡不见了」—— 一次静默的数据丢失。
 *   - 单条解密失败在我们的数据模型下不是常态，而是「密文列被写坏了」或
 *     「rewrap 做了一半」的信号（§5.3 轮换流程第 4 步）。那是要人来看的事故，
 *     不是可以吞掉的噪声。
 *
 * 真出现「一张坏卡拖垮整个列表」的情况，正确的修法是把坏行找出来修掉，
 * 而不是让门面学会假装没看见。若 T-109 之后确实需要按条容错，应当**新增**一个返回
 * `array<array-key, string|CryptoFailed>` 的方法，让容错成为调用点上看得见的选择，
 * 而不是改这个方法的语义。
 */
interface BatchDecryptorInterface
{
    /**
     * @param array<array-key, Ciphertext> $ciphertexts 空数组合法，返回空数组且**不**打 Vault
     *
     * @return array<array-key, string> 与入参同键；顺序也保持一致
     *
     * @throws CryptoUnavailable Vault 不可达 / 被封印 / 认证失败 —— 503，可重试
     * @throws CryptoFailed      批次中**任意一条**解密失败 —— 见下方关于「部分失败」的说明
     */
    public function decryptAll(CryptoKey $key, array $ciphertexts): array;
}
