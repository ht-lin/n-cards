/**
 * 每个操作至少要声明一个 4xx 响应（T-007 / §13.1 第 2 条）。
 *
 * 为什么不用现成的 JSONPath + schema 规则：`responses` 的键是状态码字符串，
 * 「至少有一个键匹配 /^4/」这件事 JSONPath 表达不了 —— 它能选出匹配的键，
 * 但选不出「一个都没匹配到」的那个操作。而「一个都没有」正是我们要报的错。
 *
 * 为什么只要求 4xx 而不是 4xx + 5xx：5xx 是横切的，每个端点都可能 500 / 503，
 * 逐个声明只是噪音；4xx 才是端点**自己**的语义（谁能调、什么算非法输入），
 * 漏掉它意味着 T-010 生成的客户端对这个端点没有任何错误分支。
 */
export default function hasErrorResponse(operation, _options, context) {
  if (operation === null || typeof operation !== 'object') {
    return;
  }

  const responses = operation.responses;

  if (responses === null || typeof responses !== 'object') {
    return [
      {
        message: '操作没有声明任何响应',
        path: [...context.path, 'responses'],
      },
    ];
  }

  const clientErrors = Object.keys(responses).filter((code) => /^4\d\d$/.test(code));

  if (clientErrors.length > 0) {
    return;
  }

  return [
    {
      message:
        '操作至少要声明一个 4xx 响应 —— 否则 T-010 生成的客户端对它没有任何错误分支。' +
        '可复用的错误响应见 components/responses/*。',
      path: [...context.path, 'responses'],
    },
  ];
}
