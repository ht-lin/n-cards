# 生成器模板覆写

`kotlin-client/` 下只有**两个**覆写文件，各改了一处。`openapi-generator` 对 `-t`
指向的模板目录做逐文件回落 —— 目录里没有的模板一律用 jar 内置的那份，所以不需要
（也**不要**）把整套 kotlin-client 模板复制过来。

## 改了什么

`data_class_req_var.mustache` / `data_class_opt_var.mustache` 里决定何时给属性加
`@Contextual` 的那一行，多加一层 `{{^isEnumRef}}` 守卫：

```mustache
{{^isModel}}@Contextual {{/isModel}}
→ {{^isModel}}{{^isEnumRef}}@Contextual {{/isEnumRef}}{{/isModel}}
```

## 为什么

`BarcodeFormat` 与 `CardRole` 是契约里 `$ref` 出去的独立枚举 schema。上游模板用
`{{^isModel}}` 判断「这个类型需不需要外部提供序列化器」，而一个 `$ref` 的枚举
`isModel` 为 false —— 于是生成出

```kotlin
@Contextual val barcodeFormat: BarcodeFormat
```

但 `BarcodeFormat` 自己就是 `@Serializable`（还带 `enumUnknownDefaultCase` 生成的
兜底 `KSerializer`）。`@Contextual` 会让 kotlinx 去 `SerializersModule` 里找一个
根本不存在的登记项，运行时抛
`Serializer for class 'BarcodeFormat' is not found`。

这不是能靠配置绕开的：`config-help -g kotlin` 里没有相关开关，
`--openapi-normalizer` 的规则表里也没有。

改完之后剩下的 `@Contextual` 恰好只有四类 —— `java.util.UUID`、`java.time.LocalDate`、
`java.time.OffsetDateTime`、`java.net.URI` —— 生成的
`infrastructure/Serializer.kt` 正好为它们登记了 adapter，加上 `Problem.current` /
`Problem.debug` 两个真正的自由形态 map（由 `core:network:impl` 登记）。

## 与「剥掉 additionalProperties」的分工

生成产物一开始还有**另外两个**毛病，都不在这里修：模型继承一个语法非法的
`HashMap<String, Any>()()` 父类，以及嵌套模型（`Card` / `ProblemFieldError` /
`User` / `Device`）也被打上 `@Contextual`。两者同源 —— §13.1 第 5 条要求的
`additionalProperties: true` 让生成器把每个对象都当成了 Map。

那一层由 `NcardsOpenApiPlugin` 派生的生成器输入解决（见该类的注释），
**不是**模板问题，所以这里不留覆写。留在这儿的只有模板自己的缺陷。

## 校验覆写没有夹带

`*.upstream-7.25.0` 是**未改动**的上游原文，和覆写版放在一起只为一件事：

```bash
cd android/core/network/api/templates
diff data_class_req_var.mustache.upstream-7.25.0 kotlin-client/data_class_req_var.mustache
diff data_class_opt_var.mustache.upstream-7.25.0 kotlin-client/data_class_opt_var.mustache
```

每个都应当**只有一行差异**。多于一行，说明有人夹带了别的改动。

## 升级 openapi-generator 时

`libs.versions.toml` 里的 `openapiGenerator` 是钉死的，升级它是一次有工作量的操作：

1. `unzip -o -q <新版 cli>.jar 'kotlin-client/data_class_*_var.mustache' -d /tmp/tpl`
2. 用新版原文替换两份 `*.upstream-<新版本>`（旧的删掉）
3. 在新原文上重做那一处改动，覆盖 `kotlin-client/` 下的两份
4. `./gradlew :core:network:api:generateApiClient`，**逐条 review 产物 diff**
5. 顺手确认上游是否已经修了这个缺陷 —— 修了就把整个 `templates/` 删掉

第 5 步是这套东西的退出条件，不要忘了它存在。
