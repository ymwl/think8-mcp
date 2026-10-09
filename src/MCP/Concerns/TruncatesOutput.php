<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Concerns;

/**
 * 超长输出截断公共逻辑，供 RunPhpTool、CommandsTool 复用
 *
 * 目的：避免 var_dump 大对象、php think list 等产生超长输出，
 * 一次性绕过限量把大量 token 灌进模型上下文。
 * 策略：单行限长（防超长单行）+ 超出行数上限时保留头尾、省略中间；
 * 调用方可通过 max_lines 参数申请更大配额（默认 300，上限 2000）。
 */
trait TruncatesOutput
{
    /**
     * 规整 max_lines 入参：默认 300，范围 1~2000
     */
    private function resolveMaxLines(mixed $value): int
    {
        $maxLines = is_numeric($value) ? (int)$value : 300;

        return min(max($maxLines, 1), 2000);
    }

    /**
     * 截断过长输出
     *
     * 注意：不使用 trait 常量（PHP 8.0/8.1 不支持），默认值通过方法参数/方法体提供。
     *
     * @param string $output        原始输出
     * @param int    $maxLines      总行数上限，不超过则原样返回；截断时头尾按 2:1 保留（错误/汇总多在尾部）
     * @param int    $maxLineLength 单行字符数上限（mb 安全截断）
     */
    private function truncateOutput(
        string $output,
        int $maxLines = 300,
        int $maxLineLength = 2000
    ): string {
        $maxLines  = max(1, $maxLines);
        $headLines = (int)floor($maxLines * 2 / 3);
        $tailLines = $maxLines - $headLines;

        $lines = explode("\n", str_replace("\r\n", "\n", $output));

        // 单行限长：防止单行超长（如 var_dump 大字符串）绕过行数限制
        foreach ($lines as $i => $line) {
            if (mb_strlen($line) > $maxLineLength) {
                $lines[$i] = mb_substr($line, 0, $maxLineLength) . '... [单行过长已截断]';
            }
        }

        $total = count($lines);
        if ($total <= $maxLines) {
            return implode("\n", $lines);
        }

        $omitted = $total - $headLines - $tailLines;

        return implode("\n", array_slice($lines, 0, $headLines))
            . "\n... [输出过长，已省略中间 {$omitted} 行（当前上限 {$maxLines} 行，可用 max_lines 调整）]\n"
            . implode("\n", array_slice($lines, -$tailLines));
    }
}
