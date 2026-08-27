<?php

declare(strict_types=1);

/*
 * 行覆盖率门禁（§13.3）。
 *
 * PHPUnit 原生只能设一个全局阈值，表达不了「某些目录要求更高」，所以这里
 * 直接读 clover 报告自己算。
 *
 * 用法：php tools/coverage-check.php var/coverage/clover.xml
 * 生成报告：composer test:coverage（需要 pcov 或 xdebug）
 */

/** 整体行覆盖率下限（§13.3）。 */
const GLOBAL_THRESHOLD = 70.0;

/**
 * 按路径前缀的更高要求（§13.3）。
 * 键是相对 backend/ 的路径 glob，值是行覆盖率下限。
 */
const PATH_THRESHOLDS = [
    'src/Module/*/Domain' => 85.0,
    'src/Module/*/Application' => 85.0,

    // T-004 补上 Shared 的两层。§13.3 的字面只写了 `Module/*`，但那条要求的**理由**
    // （Domain 与 Application 是不变量与编排的所在，测不到就等于没保障）对全仓库
    // 复用率最高的 Shared 内核只会更成立 —— 这里的每个类都会被七个模块 import。
    //
    // 不含 Shared/Infrastructure 与 Shared/Http：那两层是框架适配（监听器、控制器
    // 基类、Doctrine 类型），端到端行为由 tests/Api 覆盖，行覆盖率不是衡量它们的
    // 好指标，硬卡 85% 只会诱导为覆盖率而写的测试。
    'src/Shared/Domain' => 85.0,
    'src/Shared/Application' => 85.0,
];

$cloverPath = $argv[1] ?? 'var/coverage/clover.xml';

if (!is_file($cloverPath)) {
    fwrite(STDERR, sprintf(
        "找不到覆盖率报告 %s。\n先跑 `composer test:coverage`（需要 pcov 或 xdebug）。\n",
        $cloverPath,
    ));

    exit(1);
}

$xml = simplexml_load_file($cloverPath);

if (false === $xml) {
    fwrite(STDERR, sprintf("无法解析 %s\n", $cloverPath));

    exit(1);
}

$projectRoot = \dirname(__DIR__);

/** @var array<string, array{covered: int, total: int}> $perFile */
$perFile = [];

foreach ($xml->xpath('//file') ?: [] as $file) {
    $absolute = (string) $file['name'];
    $relative = str_starts_with($absolute, $projectRoot.'/')
        ? substr($absolute, \strlen($projectRoot) + 1)
        : $absolute;

    $metrics = $file->metrics;

    if (null === $metrics) {
        continue;
    }

    $perFile[$relative] = [
        'covered' => (int) $metrics['coveredstatements'],
        'total' => (int) $metrics['statements'],
    ];
}

/**
 * @param array<string, array{covered: int, total: int}> $files
 *
 * @return array{covered: int, total: int}
 */
function sumMatching(array $files, ?string $globPrefix = null): array
{
    $covered = 0;
    $total = 0;

    foreach ($files as $path => $counts) {
        if (null !== $globPrefix && !fnmatch($globPrefix.'/*', $path)) {
            continue;
        }

        $covered += $counts['covered'];
        $total += $counts['total'];
    }

    return ['covered' => $covered, 'total' => $total];
}

$failures = [];
$lines = [];

/**
 * @param array{covered: int, total: int} $counts
 * @param list<string>                    $failures
 * @param list<string>                    $lines
 */
function report(string $label, array $counts, float $threshold, array &$failures, array &$lines): void
{
    if (0 === $counts['total']) {
        // 可覆盖行数为 0：M0 阶段模块还是空壳，这不是失败。
        $lines[] = sprintf('  %s %-38s   n/a  (无可覆盖代码)  下限 %.0f%%', '-', $label, $threshold);

        return;
    }

    $percent = $counts['covered'] / $counts['total'] * 100;
    $ok = $percent >= $threshold;
    $lines[] = sprintf(
        '  %s %-38s %5.1f%%  (%d/%d)  下限 %.0f%%',
        $ok ? '✓' : '✗',
        $label,
        $percent,
        $counts['covered'],
        $counts['total'],
        $threshold,
    );

    if (!$ok) {
        $failures[] = sprintf('%s 行覆盖率 %.1f%% < %.0f%%', $label, $percent, $threshold);
    }
}

report('整体', sumMatching($perFile), GLOBAL_THRESHOLD, $failures, $lines);

foreach (PATH_THRESHOLDS as $glob => $threshold) {
    // 把 `src/Module/*/Domain` 展开成实际存在的目录，逐个单独判定 ——
    // 合并统计会让一个高覆盖率的模块掩盖掉另一个没写测试的模块。
    $expanded = glob($projectRoot.'/'.$glob, \GLOB_ONLYDIR) ?: [];

    foreach ($expanded as $absolute) {
        $relative = substr($absolute, \strlen($projectRoot) + 1);
        report($relative, sumMatching($perFile, $relative), $threshold, $failures, $lines);
    }
}

echo "行覆盖率门禁（§13.3）\n";
echo implode("\n", $lines), "\n\n";

if ([] !== $failures) {
    fwrite(STDERR, "✗ 覆盖率未达标：\n  - ".implode("\n  - ", $failures)."\n");

    exit(1);
}

echo "✓ 覆盖率达标\n";
