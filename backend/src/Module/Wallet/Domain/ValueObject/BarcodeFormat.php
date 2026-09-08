<?php

declare(strict_types=1);

namespace App\Module\Wallet\Domain\ValueObject;

/**
 * `cards.barcode_format`（§5.2）—— 契约 `BarcodeFormat` 的 13 个值，逐字对应。
 *
 * ============================================================================
 * ⚠️ 这一列**没有** CHECK 约束，是刻意的
 * ============================================================================
 * §17.1 给 `cards.title` / `merchant_label` 写了 `CHECK (char_length(…) <= 100)`，
 * 却**没有**给 `barcode_format` 写 `CHECK (barcode_format IN (…))`。
 * 与 `users` 上的取舍逐字同源（见 `IdentitySchemaTest` 钉住的那条）：
 *
 *   §13.6 允许**新增**枚举值。值域写进 CHECK 之后，加一个 `RM_QR_CODE`
 *   就成了一次要停机对齐的迁移 —— 而这个枚举的用途只是让服务端把客户端发来的
 *   字符串原样存回去，它不参与任何服务端判定。
 *
 * 值域的强制点因此只有一处：{@see tryFrom()}，在 `CardCreatePayload` /
 * `CardUpdatePayload` 里。发一个不认识的格式是 `400 validation_failed`。
 *
 * ============================================================================
 * ⚠️ 客户端侧必须有 UNKNOWN 兜底
 * ============================================================================
 * 契约的 description 逐字要求：「客户端**必须**把未知值当作 `UNKNOWN` 并渲染
 * 兜底样式」。也就是说**服务端这一侧加值是向后兼容的**，而删值不是 ——
 * 删掉一个 case 会让库里既有的行读不回来（`from()` 抛 `\ValueError`）。
 * 只增不删。
 */
enum BarcodeFormat: string
{
    // ------------------------------------------------------------------ 一维
    case Ean13 = 'EAN_13';
    case Ean8 = 'EAN_8';
    case UpcA = 'UPC_A';
    case UpcE = 'UPC_E';
    case Code128 = 'CODE_128';
    case Code39 = 'CODE_39';
    case Code93 = 'CODE_93';
    case Itf = 'ITF';
    case Codabar = 'CODABAR';

    // ------------------------------------------------------------------ 二维
    case QrCode = 'QR_CODE';
    case Aztec = 'AZTEC';
    case Pdf417 = 'PDF_417';
    case DataMatrix = 'DATA_MATRIX';
}
