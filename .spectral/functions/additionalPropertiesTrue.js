/**
 * 每个 object schema 都必须显式写 `additionalProperties: true`（§13.1 第 5 条）。
 *
 * ============================================================================
 * 为什么这条规则值得写一个函数
 * ============================================================================
 * 它是**前向兼容的地基**。§13.6 允许随时给响应加字段，而 Kotlin 的
 * `Json { ignoreUnknownKeys = true }` 只解决反序列化那一半 —— 另一半是契约本身
 * 必须说「将来可能会多出字段」。写成 `additionalProperties: false`，或者干脆
 * 不写（在 JSON Schema 里等价于 true，但对 review 与生成器都不是显式承诺），
 * 都会让「加一个响应字段」在某个消费者那里变成破坏性变更。
 *
 * 之所以不能用 `given: $..[?(@.type == 'object')]` 一行搞定：
 * 内联 schema 嵌在 properties / items / allOf / oneOf / anyOf 之下，深度不定，
 * 且 `$..` 会同时选中 example、components/responses 里的对象等一大堆非 schema
 * 节点。所以这里从几个明确的根开始，自己往下走，只在**确实是 schema** 的节点上判断。
 *
 * 跳过 $ref 节点：它的约束在被指向的地方，重复要求只会制造假阳性。
 * 特别是 components/schemas/Problem —— 它整个就是一个指向
 * docs/api/schemas/problem-details.schema.json 的 $ref，那份文件自己带
 * additionalProperties: true（由 ProblemDetailsSchemaTest 断言）。
 */

/** schema 里会嵌套子 schema 的键。 */
const SCHEMA_MAP_KEYS = ['properties', 'patternProperties', '$defs', 'definitions'];
const SCHEMA_LIST_KEYS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];
const SCHEMA_SINGLE_KEYS = ['items', 'not', 'additionalProperties', 'contains'];

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** 这个节点声明的类型里包含 object 吗？ */
function declaresObject(schema) {
  const type = schema.type;

  if (type === 'object') {
    return true;
  }

  // OpenAPI 3.1 / JSON Schema 2020-12：type 可以是数组，如 ["object", "null"]。
  if (Array.isArray(type) && type.includes('object')) {
    return true;
  }

  // 没写 type 但写了 properties/required 的，实际就是个 object schema。
  return type === undefined && (isPlainObject(schema.properties) || Array.isArray(schema.required));
}

function walk(schema, path, findings, seen) {
  if (!isPlainObject(schema) || seen.has(schema)) {
    return;
  }

  seen.add(schema);

  // $ref 节点的约束在别处，不在这里判。
  if (typeof schema.$ref === 'string') {
    return;
  }

  if (declaresObject(schema) && schema.additionalProperties !== true) {
    findings.push({
      message:
        'object schema 必须显式写 `additionalProperties: true`（§13.1 第 5 条）。' +
        '这是前向兼容的地基：§13.6 允许随时新增响应字段，老客户端不能因此崩。',
      path: [...path],
    });
  }

  for (const key of SCHEMA_MAP_KEYS) {
    if (isPlainObject(schema[key])) {
      for (const [name, child] of Object.entries(schema[key])) {
        walk(child, [...path, key, name], findings, seen);
      }
    }
  }

  for (const key of SCHEMA_LIST_KEYS) {
    if (Array.isArray(schema[key])) {
      schema[key].forEach((child, index) => walk(child, [...path, key, index], findings, seen));
    }
  }

  for (const key of SCHEMA_SINGLE_KEYS) {
    // additionalProperties: true 是布尔量，不是子 schema —— isPlainObject 挡掉了。
    if (isPlainObject(schema[key])) {
      walk(schema[key], [...path, key], findings, seen);
    }
  }
}

export default function additionalPropertiesTrue(schema, _options, context) {
  const findings = [];

  walk(schema, context.path, findings, new WeakSet());

  return findings.length > 0 ? findings : undefined;
}
