/**
 * 断言一个操作声明了某个必填的请求头参数。
 *
 * ============================================================================
 * 为什么需要它
 * ============================================================================
 * OpenAPI 没有「全局请求头」这个概念 —— `security` 能全局声明，请求头不能。
 * 而 §6.1 要求 `X-Client` 对**所有** `/v1` 请求必填，后端也确实是全局强制的
 * （ClientVersionListener 对 ApiSurface::isProductApiPath() 为真的路径一律拦）。
 *
 * 于是有一个只会在运行时暴露的坑：新加一个操作、忘了 $ref XClient，
 * 契约会说「这个端点不需要 X-Client」，Spectral 不会有意见，T-010 生成的
 * Retrofit 方法签名里也就没有这个参数 —— 直到真的发出请求，拿到 400。
 * 这条规则把那个时刻提前到 lint。
 *
 * 两种写法都认：`$ref` 到指定的 components/parameters，或就地写一个同名
 * 且 `required: true` 的 header 参数。前者是本仓库的约定，后者兜底。
 */
export default function requiresHeaderParameter(operation, options, context) {
  if (operation === null || typeof operation !== 'object') {
    return;
  }

  const { ref, name } = options ?? {};
  const parameters = Array.isArray(operation.parameters) ? operation.parameters : [];

  const declared = parameters.some((parameter) => {
    if (parameter === null || typeof parameter !== 'object') {
      return false;
    }

    if (ref !== undefined && parameter.$ref === ref) {
      return true;
    }

    return parameter.in === 'header' && parameter.name === name && parameter.required === true;
  });

  if (declared) {
    return;
  }

  return [
    {
      message:
        `操作必须声明必填的 \`${name}\` 请求头（推荐 \`$ref: '${ref}'\`）。` +
        'OpenAPI 没有全局请求头，只能逐操作写；漏掉的话契约会说这个端点不需要它，' +
        '而后端会在运行时返回 400 —— 契约与实现当场不一致。',
      path: [...context.path, 'parameters'],
    },
  ];
}
