<?php

declare(strict_types=1);

/*
 * 代码风格（§12.2 / §13.3）：@Symfony + @PHP83Migration，`php-cs-fixer check` 必须 0 差异。
 *
 * 相对规格的一处增补：额外开启 `declare_strict_types`（属 risky 规则，故 setRiskyAllowed(true)）。
 * 理由是 Domain 层大量使用值对象与标量参数，弱类型强制转换会把本该抛异常的非法输入
 * 悄悄转成合法值 —— 这与 §12.2「Domain 负责不变量」直接冲突。
 *
 * 缩进等基础风格由仓库根 .editorconfig 规定（PHP = 4 空格），与 @Symfony 一致。
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['var', 'vendor'])
    ->notPath([
        // Symfony 自动生成，不由我们维护
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@PHP83Migration' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')
;
