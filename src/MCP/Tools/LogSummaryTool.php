<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Tools;

use think\App;
use ymwl\think8mcp\MCP\Concerns\ReadsLogs;

/**
 * 日志摘要工具 - 按级别计数 + Top 错误/警告消息聚合
 *
 * 用于"先看有什么错"再精准深挖：把整日日志压缩成几十行摘要，
 * 再配合 get_logs 的 keyword 过滤定位具体行。
 */
class LogSummaryTool implements ToolInterface
{
    use ReadsLogs;

    /** 单文件最多统计的字节数（8MB），超出则只统计末尾部分 */
    private const MAX_SCAN_BYTES = 8 * 1024 * 1024;

    /** Top 条目默认/最大数量 */
    private const DEFAULT_TOP = 8;
    private const MAX_TOP     = 20;

    /** 消息签名（分组键）最大长度 */
    private const SIGNATURE_LENGTH = 120;

    /** 参与 Top 聚合的级别 */
    private const TOP_LEVELS = ['error', 'warning', 'critical', 'alert', 'emergency'];

    public function __construct(
        private App $app
    ) {}

    public function getName(): string
    {
        return 'get_log_summary';
    }

    public function getDescription(): string
    {
        return '统计日志全景：按级别计数 + Top 错误/警告消息聚合（次数、首末时间），用于先看整体再精准深挖。支持指定日期。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'date' => [
                    'type'        => 'string',
                    'description' => '日志日期，格式 YYYYMMDD（可选），不填则统计最新日志',
                    'pattern'     => '^[0-9]{8}$',
                ],
                'top' => [
                    'type'        => 'integer',
                    'description' => 'Top 错误聚合条数（默认 8，最大 20）',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_TOP,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): string
    {
        $date = isset($params['date']) ? trim((string)$params['date']) : null;
        $top  = min(max((int)($params['top'] ?? self::DEFAULT_TOP), 1), self::MAX_TOP);

        if ($date === '') {
            $date = null;
        }

        if ($date !== null && !preg_match('/^\d{8}$/', $date)) {
            return "错误：date 参数格式不正确，应为 YYYYMMDD，例如 \"20240516\"。";
        }

        $logPath = $this->getLogPath();

        if (!is_dir($logPath)) {
            return "日志目录不存在：{$logPath}\n\nThinkPHP 应用需要先运行才会生成日志目录。";
        }

        $files = $this->resolveSummaryFiles($logPath, $date);

        if (empty($files)) {
            return $date !== null
                ? "未找到日期 {$date} 的日志文件。"
                : "日志目录 {$logPath} 中没有找到任何日志文件。";
        }

        $levelCounts = [];
        $groups      = [];
        $totalLines  = 0;
        $firstTime   = '';
        $lastTime    = '';
        $truncated   = false;

        foreach ($files as $file) {
            [$lines, $cut] = $this->readLogLines($file);
            $truncated     = $truncated || $cut;

            foreach ($lines as $line) {
                $totalLines++;

                if (!preg_match('/^\[([^\]]+)\]\[([^\]]+)\]\s?(.*)$/', $line, $m)) {
                    continue;
                }

                $time  = $this->extractClock($m[1]);
                $level = strtolower(trim($m[2]));
                $msg   = trim($m[3]);

                if ($time !== '') {
                    if ($firstTime === '' || $time < $firstTime) {
                        $firstTime = $time;
                    }

                    if ($lastTime === '' || $time > $lastTime) {
                        $lastTime = $time;
                    }
                }

                $levelCounts[$level] = ($levelCounts[$level] ?? 0) + 1;

                if (!in_array($level, self::TOP_LEVELS, true)) {
                    continue;
                }

                $sig = mb_substr($msg, 0, self::SIGNATURE_LENGTH);

                if (!isset($groups[$sig])) {
                    $groups[$sig] = ['level' => $level, 'count' => 0, 'first' => $time, 'last' => $time];
                }

                $groups[$sig]['count']++;
                $groups[$sig]['last'] = $time;
            }
        }

        // 组装输出
        $fileNames = implode(' + ', array_map('basename', $files));
        $output    = sprintf("日志摘要 %s（%s，共 %d 行）\n", $date ?? '最新', $fileNames, $totalLines);

        if ($truncated) {
            $output .= '（文件较大，仅统计各文件末尾 ' . $this->formatFileSize(self::MAX_SCAN_BYTES) . "）\n";
        }

        arsort($levelCounts);
        $levelParts = [];
        foreach ($levelCounts as $level => $count) {
            $levelParts[] = "{$level} {$count}";
        }

        $output .= '级别统计: ' . ($levelParts === [] ? '（无标准格式日志行）' : implode(' | ', $levelParts)) . "\n";

        if ($firstTime !== '') {
            $output .= "时间范围: {$firstTime} ~ {$lastTime}\n";
        }

        uasort($groups, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        $topGroups = array_slice($groups, 0, $top, true);

        if ($topGroups === []) {
            $output .= "\n无 error/warning 级别记录。\n";
        } else {
            $output .= sprintf("\nTop %d 错误/警告:\n", count($topGroups));
            foreach ($topGroups as $sig => $group) {
                $output .= sprintf(
                    "×%-4d %s~%s  [%s]  %s\n",
                    $group['count'],
                    $group['first'],
                    $group['last'],
                    $group['level'],
                    $sig
                );
            }
        }

        return $output;
    }

    /**
     * 解析待统计的日志文件：指定日期取当日候选（含 *_error.log），否则取最新常规 + 最新错误日志
     *
     * @return string[]
     */
    private function resolveSummaryFiles(string $logPath, ?string $date): array
    {
        $files = [];

        if ($date !== null) {
            foreach ($this->logDateCandidates($logPath, $date) as $candidate) {
                if (is_file($candidate)) {
                    $files[] = $candidate;
                }
            }

            return array_values(array_unique($files));
        }

        $latest = $this->findLatestLogFile($logPath);
        if ($latest !== null) {
            $files[] = $latest;
        }

        $errorLatest = $this->findLatestLogFile($logPath, '_error.log');
        if ($errorLatest !== null && !in_array($errorLatest, $files, true)) {
            $files[] = $errorLatest;
        }

        return $files;
    }

    /**
     * 读取日志行（末尾 MAX_SCAN_BYTES 以内）
     *
     * @return array{0: string[], 1: bool} [行数组, 是否因超限被截断]
     */
    private function readLogLines(string $filePath): array
    {
        $fileSize = @filesize($filePath);

        if ($fileSize === false || $fileSize === 0) {
            return [[], false];
        }

        $fp = @fopen($filePath, 'r');
        if ($fp === false) {
            return [[], false];
        }

        try {
            $content = $this->readTailContent($fp, (int)$fileSize, self::MAX_SCAN_BYTES);
        } finally {
            fclose($fp);
        }

        $lines = array_values(array_filter(
            explode("\n", $content),
            static fn(string $line): bool => trim($line) !== ''
        ));

        return [$lines, $fileSize > self::MAX_SCAN_BYTES];
    }

    /**
     * 从时间戳提取 HH:MM:SS（兼容 2026-10-08T23:42:08+08:00 与 2026-10-08 23:42:08）
     */
    private function extractClock(string $timestamp): string
    {
        if (preg_match('/[T ](\d{2}:\d{2}:\d{2})/', $timestamp, $m)) {
            return $m[1];
        }

        return '';
    }
}
