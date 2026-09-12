# 0023. BarcodeFormat 的词汇表放 `core:model`，三列适配表放 `core:barcode` 的同一个 `when`

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Android 负责人
- **规格引用**: §10.1、§12.3、§4.3、§13.6
- **影响**：T-152（本卡）、T-153、T-154、T-155、T-156、T-157

## Context

§10.1 的原文是：

> **格式映射表**（ML Kit → ZXing → 展示名）必须集中在 `core:model` 的单一
> `BarcodeFormat` 枚举中，禁止在各处散落 `when`。

而 §12.3 的目录树里，`core:barcode` 的说明是「**格式映射唯一实现处**」。
两句话指向两个不同的模块，而且第一句**字面上做不到**：

1. ML Kit 的 `Barcode.FORMAT_*` 是 Android 常量，而 `core:model` 是
   `ncards.jvm.library` —— 「连 `android.*` 都 import 不到」是它刻意造成的结构约束。
2. 展示名必须住在 `strings.xml` 里（§11.1，`MissingTranslation` 是 error），
   而 `core:model` 没有 `res/`。

同时 T-152 在实现中发现，ZXing 的静区（quiet zone）语义远比规格假设的复杂，
「按目标尺寸生成」这条要求也不能直接交给 ZXing —— 见决定三。

## Decision

### 一、词汇表在 `core:model`，三列适配表在 `core:barcode`，但全仓只有**一个** `when`

- `core:model` 的 `BarcodeFormat` 持有：13 个码制 + `UNKNOWN`、`wireName`、
  `dimension`（一维/二维）、`MAX_PAYLOAD_BYTES`、`fromWire()`。
- `core:barcode` 的 `BarcodeFormatTable` 持有 ZXing 常量 / ML Kit int / `@StringRes`
  三列，由**一个** `rowOf(format): Row` 的穷举 `when` 一次产出。

§10.1 的**意图**（一张表、没有散落的 `when`）因此被满足得比字面更严格：
全仓只有那一处 `when (format)`，加第 14 个码制会在那一处编译失败，三列被迫同时补齐。
三个各自穷举的 `when` 反而允许漏改其中一个而不报错。

形状与 `core:model` 已有的 `AppLanguageStore`（T-151）一致：
词汇定义在下游看得见的地方，Android 侧的实现从上游绑进来。

**被否决的方案：把 ZXing 放进 `core:model`。** `com.google.zxing:core` 是纯 JVM jar，
这么做是**编译得过**的 —— 正因为编译得过，否决理由必须留档：`data:auth`、
每个 `feature:*` 与 `:app` 都已经依赖 `core:model`，把一个编码库塞进它们的运行时
类路径，并让那个模块「纯 Kotlin 领域模型」的定位变成假话，换来的只是少一个文件。

**`UNKNOWN` 是第 14 个枚举常量，不是可空返回。** §13.6 允许服务端新增枚举值、
老客户端不得因此崩（契约里逐字写了这条）。可空会被某处的 `!!` 消掉；常量则出现在
每一个穷举 `when` 里，逼每个消费方显式决定「这种卡长什么样」。
代价是 `entries.size == 14` 而规格说「13 种码制」—— 两者都没写错。

### 二、只引 `com.google.zxing:core`，ML Kit 只取编译期常量

- **不引 `zxing-android-embedded`**（§4.3 选型表原文）：它自带 `CaptureActivity`
  与整套相机代码，与 §10.1 的 CameraX + ML Kit 扫描路线正面冲突。
- ZXing 的 **reader 只出现在单测源集**（render → decode 往返，覆盖全部 13 种码制）。
  主源集一个 reader 都不碰。
- `com.google.mlkit:barcode-scanning-common` 用 **`compileOnly`**：这里只用
  `Barcode.FORMAT_*` 那十几个 `static final int`，它们在编译期被内联，运行期不需要
  这个 artifact —— 而它的 POM 会拖 `play-services-basement` + `vision-common` 进来。
  引常量而不是抄数字，是为了让值与 AAR 绑定，升版时不可能静默漂移。
  T-156 做相机扫描时换成 bundled 的 `com.google.mlkit:barcode-scanning`，那是它的预算。

### 三、渲染器自己接管静区与缩放，只向 ZXing 要 1× 的裸模块矩阵

`MultiFormatWriter().encode(value, format, 0, 0, MARGIN = 0)` 拿到恰好等于模块数的
矩阵，之后的静区、整数缩放、像素展开全部自己做。

理由是实测出来的（zxing 3.5.4）：**`EncodeHintType.MARGIN` 有五套互不相同的语义**，
其中两套压根不存在。

| 码制 | 读 `MARGIN`？ | 默认值 | 单位 |
|---|---|---|---|
| Code 128/39/93、ITF、Codabar | 是 | 10 | 模块，**两侧总和**（每侧 5） |
| EAN-13/8、UPC-A/E | 是 | **9** | 模块，**两侧总和**（每侧 4.5） |
| QR_CODE | 是 | 4 | 模块，**每侧** |
| PDF_417 | 是 | 30 | **输出像素**，每侧 |
| **AZTEC、DATA_MATRIX** | **否，hint 被完全忽略** | **0** | 完全没有静区 |

照 §10.1 字面传 `MARGIN = 10` 的结果是：一维码每侧只有 5 模块（EAN/UPC 4.5），
而 Aztec 与 DataMatrix **一圈静区都没有**。实测静区为 0 时，ZXing 自己的 reader
在 4 / 12 / 33 px 三档模块宽下**全部**解不出来。

另有两条同源的理由：

- **装不下时 ZXing 静默返回比请求更大的矩阵**（五个 writer 都是
  `outputWidth = Math.max(width, fullWidth)`）。按请求尺寸开像素数组会越界；
  而矩阵比 View 大时 View 会做**非整数降采样**，那正是 §10.1
  「不要生成小图再放大」要防的模糊，只是方向相反。
- **DataMatrix 强制方形符号**（`SymbolShapeHint.FORCE_SQUARE`）。ZXing 默认允许矩形，
  而矩形的 DataMatrix 在模块宽 33 px 时连 ZXing 自己的 reader 都读不出来
  （4 px / 12 px 能读）—— 也就是**尺寸越大越读不出**，而全屏条码页正是最大的那一档。

## Consequences

**正面**

- §10.1 的「静区 ≥ 10 模块」变成 `QuietZone` 里的一个常量，而不是五处 writer 的偶然。
- Aztec 与 DataMatrix 拿到了 ZXing 不会给的静区。
- 模块宽保证是整数像素（防模糊的真正条件），尺寸不足时给出可操作的 `TooSmall`。
- 从格式映射到 ARGB 数组全是纯 Kotlin，`:core:barcode` 靠单测达到 **88.3%** 行覆盖
  （门禁 70%），唯一碰 `android.*` 的只有三行的 `AndroidBarcodeBitmapFactory`。

**负面 / 代价**

- 我们自己多了约 120 行像素代码要维护和测。
- **每次升级 ZXing 都要重验 `(0, 0) + MARGIN = 0` 探针的行为** ——
  它是整个设计的地基。`BarcodeRoundTripTest` 覆盖 13 种码制，是这条的兜底。
- 强制方形 DataMatrix 意味着渲染出来的符号与用户扫进来的那张**形状可能不同**。
  码值不变，扫出来仍是同一个字符串，但视觉上不是原样复刻。

**一条现有门禁结构上看不见的泄漏**

ZXing 多条异常消息里嵌着码值片段（Code 39 的 `non-encodable character: 'X'`、
Codabar 的 `Cannot encode : '…'`）。而 `scripts/ci/check-sensitive-logs.sh` 是两段式的：
只拦 `barcodeValue` / `rawValue` 出现在日志调用那一行的情形，**拦不住 `Timber.w(e)`**。
因此约定：**只记异常类名与 `BarcodeFormat`，永远不记 `e.message`**，也不解析它。
`BarcodeRenderResult.InvalidPayload` 因此不带原因；逐字段的原因与德语文案归 T-155。

## Alternatives

- **严格照 §10.1 字面做**（三列全塞进 `core:model` 的枚举）：ML Kit 常量与
  `@StringRes` 都进不去，做不到。
- **三个适配文件各管一段**：满足「各自唯一」但不满足「加一个码制必须同时改三处」——
  漏改一个不会编译失败，而这正是 §10.1 要防的腐化。
- **把静区交给 ZXing 的 `MARGIN`**：五套语义，其中两种码制完全没有静区。
- **展示名做成枚举上的 `String` 常量**（能满足 §10.1 的字面要求）：
  违反 §11.1「所有用户可见字符串必须在 `strings.xml`」，且 14 条里有 3 条德英确实不同。
