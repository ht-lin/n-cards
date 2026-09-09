<?php

declare(strict_types=1);

namespace App\Module\Wallet\Http;

use App\Module\Wallet\Application\Card\CardView;

/**
 * {@see CardView} → 契约 `Card` schema 的数组。
 *
 * 与 {@see \App\Module\Identity\Http\UserBody} 同一个角色、同一条理由：
 * 契约里 `Card` 是**一个** schema，而服务端有五个端点会返回它
 * （`GET` 列表 / `POST` / `GET` 单卡 / `PATCH`，T-110 还要加 `PUT …/placement`）。
 * 组装代码有第二份的话，加字段时必然漏一处 —— 而漏掉的那个端点的契约测试
 * 照样是绿的（`Card` 的 `required` 里没有那个新字段）。
 *
 * ⚠️ 本类**不做任何判断**，只搬字段。`my_role` / `can_edit` 该是什么
 * 由 {@see \App\Module\Wallet\Application\Card\CardViewAssembler} 决定 ——
 * deptrac 里 `Wallet.Http` 看不见 `Wallet.Domain`，所以这里想判断也判断不了，
 * 这正是那条规则想要的效果。
 */
final readonly class CardBody
{
    /**
     * @return array<string, mixed>
     */
    public static function of(CardView $card): array
    {
        return [
            'id' => $card->id->toString(),
            'title' => $card->title,
            'merchant_label' => $card->merchantLabel,
            'color' => $card->color,
            'barcode_format' => $card->barcodeFormat,
            'barcode_value' => $card->barcodeValue,
            'note' => $card->note,
            // DATE 列，发的是 `YYYY-MM-DD` —— 契约写的是 `format: date`，
            // 不是 date-time。发成完整时间戳会让客户端的日期解析炸掉。
            'expires_on' => $card->expiresOn?->format('Y-m-d'),
            'owner_id' => $card->ownerId->toString(),
            'my_role' => $card->myRole,
            'can_edit' => $card->canEdit,
            'revision' => $card->revision,
            // 与 UserBody 逐字相同的时间格式：先转 UTC 再格式化。
            // 不转的话，服务器时区一变，响应里的字面量就跟着变 ——
            // 而契约的 `format: date-time` 与客户端的解析器都假定它是 Z 结尾的 UTC。
            'updated_at' => $card->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
