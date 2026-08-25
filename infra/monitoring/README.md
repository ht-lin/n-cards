# infra/monitoring

Prometheus / Grafana / Loki 配置。**由 T-405 交付**，当前目录为空。

范围见 §14.4：SLI 埋点、P0/P1 告警、核心看板。

## 约束

日志**绝不**包含个人数据：禁止出现 `barcode_value`、`email` 的明文或变量插值；实体对象不得整体打印。CI 有敏感日志扫描（§13.3）。

`X-Request-Id` 必须贯穿所有日志，是排障的唯一关联键。

> 完整 Grafana 看板属于**可裁剪项**（R5 触发时第 4 顺位砍）。P0/P1 告警与核心 SLI 埋点不可裁剪。
