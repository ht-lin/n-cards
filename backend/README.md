# backend

Symfony 7.x / PHP 8.3+ **模块化单体**。**由 T-002 交付**，当前目录为空。

## 结构（§12.2）

```
src/
├── Shared/{Domain,Application,Infrastructure,Http}
└── Module/{Identity,Wallet,Sharing,Social,Sync,Notification,Compliance}
```

## 分层职责

| 层 | 允许做 | 禁止做 |
|---|---|---|
| `Http/Controller` | 反序列化请求、调用 Application、序列化响应 | 任何业务逻辑、直接用 Doctrine |
| `Application` | 编排、事务边界、权限检查、DTO 组装 | 直接写 SQL、了解 HTTP |
| `Domain` | 实体、值对象、不变量、领域服务、领域事件 | import 任何框架类型 |
| `Infrastructure` | Doctrine 映射与仓储实现、外部 HTTP 客户端、Vault、Mailer | 被 Domain 直接引用（只能实现其接口） |

## 模块边界（deptrac 强制，两个维度）

1. **模块间**：不得引用他模块的 `Domain` / `Infrastructure`，只能经 `Application\Port\*`。
2. **层间**：`Http → Application → Domain`；`Domain` 不得 import 任何框架类型。

唯一豁免：`Sync\Infrastructure\Doctrine\SyncReadModel`（跨模块只读查询入口），在 `deptrac.yaml` 中显式标注。

## 质量门禁（§13.3）

`php-cs-fixer` 0 差异 · `phpstan` level 8 全量 0 error · `deptrac` 0 violation · `composer audit` 0 高危 · 覆盖率整体 ≥ 70%，`Module/*/Domain` 与 `Application` ≥ 85%。
