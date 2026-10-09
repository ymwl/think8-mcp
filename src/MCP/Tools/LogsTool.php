<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Tools;

use think\App;
use ymwl\think8mcp\MCP\Concerns\ReadsLogs;

/**
 * 日志工具 - 读取 ThinkPHP runtime/log 目录下的日志文件
 */
class LogsTool implements ToolInterface
{
    use ReadsLogs;

    private const DEFAULT_LINES = 100;
    private const MAX_LINES     = 1000;

    /** 过滤模式下从文件末尾向前扫描的最大行数（防超大日志卡死） */
    private const MAX_SCAN_LINES = 200000;

    public function __construct(
        private App $app
    ) {}

    public function getName(): string
    {
        return 'get_logs';
    }

    public function getDescription(): string
    {
        return '读取 ThinkPHP 应用的运行日志（runtime/log 目录）。支持日期、级别、关键字过滤与行数控制。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'lines' => [
                    'type'        => 'integer',
                    'description' => '读取的日志行数（默认 100，最多 1000）',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LINES,
                ],
                'date' => [
                    'type'        => 'string',
                    'description' => '日志日期，格式为 YYYYMMDD，例如 "20240516"（可选，不填则读取最新日志）',
                    'pattern'     => '^[0-9]{8}$',
                ],
                'level' => [
                    'type'        => 'string',
                    'description' => '过滤日志级别：error、warning、info、debug（可选，不填则返回所有级别）',
                    'enum'        => ['error', 'warning', 'info', 'debug', 'notice', 'sql'],
                ],
                'keyword' => [
                    'type'        => 'string',
                    'description' => '按关键字过滤日志行（不区分大小写，可与 date/level 组合使用）。',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): string
    {
        $lines    = min((int)($params['lines'] ?? self::DEFAULT_LINES), self::MAX_LINES);
        $lines    = max($lines, 1);
        $date     = isset($params['date']) ? trim((string)$params['date']) : null;
        $level    = isset($params['level']) ? strtolower(trim((string)$params['level'])) : null;
        $keyword  = isset($params['keyword']) ? trim((string)$params['keyword']) : null;

        if ($keyword === '') {
            $keyword = null;
        }

        // 验证日期格式
        if ($date !== null && !preg_match('/^\d{8}$/', $date)) {
            return "错误：date 参数格式不正确，应为 YYYYMMDD，例如 \"20240516\"。";
        }

        $logPath = $this->getLogPath();

        if (!is_dir($logPath)) {
            return "日志目录不存在：{$logPath}\n\nThinkPHP 应用需要先运行才会生成日志目录。";
        }

        if ($date !== null) {
            return $this->readLogByDate($logPath, $date, $lines, $level, $keyword);
        }

        return $this->readLatestLog($logPath, $lines, $level, $keyword);
    }

    /**
     * 读取最新的日志文件
     */
    private function readLatestLog(string $logPath, int $lines, ?string $level, ?string $keyword): string
    {
        $logFile = $this->findLatestLogFile($logPath);

        if ($logFile === null) {
            return "日志目录 {$logPath} 中没有找到任何日志文件。";
        }

        return $this->readLogFile($logFile, $lines, $level, $keyword);
    }

    /**
     * 按日期读取日志文件
     */
    private function readLogByDate(string $logPath, string $date, int $lines, ?string $level, ?string $keyword): string
    {
        $logFile = $this->findLogFileByDate($logPath, $date);

        if ($logFile === null) {
            return "未找到日期 {$date} 的日志文件。\n\n已搜索以下路径：\n"
                . implode("\n", $this->logDateCandidates($logPath, $date));
        }

        return $this->readLogFile($logFile, $lines, $level, $keyword);
    }

    /**
     * 读取日志文件内容（取最后 N 行或最后 N 条匹配）
     */
    private function readLogFile(string $filePath, int $lines, ?string $level, ?string $keyword): string
    {
        if (!is_readable($filePath)) {
            return "无法读取日志文件：{$filePath}（权限不足）";
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            return "日志文件为空：{$filePath}";
        }

        $filtered = ($level !== null || $keyword !== null);
        $complete = true;

        if (!$filtered) {
            // 无过滤：直接从文件末尾读取指定行数
            $allLines = $this->readTailLines($filePath, $lines + 1);
            $allLines = array_values(array_filter(array_map('rtrim', explode("\n", $allLines))));
        } else {
            // 有过滤：渐进扫描，尽量凑足 $lines 条匹配（避免"只读末尾"造成的匹配假阴性）
            $result   = $this->collectFilteredTail($filePath, $lines, $level, $keyword);
            $allLines = $result['lines'];
            $complete = $result['complete'];
        }

        // 取最后 N 行
        if (count($allLines) > $lines) {
            $allLines = array_slice($allLines, -$lines);
        }

        if (empty($allLines)) {
            $suffix = $complete
                ? ''
                : "\n\n注意：已扫描至扫描上限（" . self::MAX_SCAN_LINES . " 行），更早内容未扫描。";

            return "日志文件 {$filePath} 中没有找到匹配的日志记录"
                . $this->describeFilters($level, $keyword) . "。{$suffix}";
        }

        $scope = $filtered
            ? sprintf('匹配 %d 行', count($allLines))
            : sprintf('最后 %d 行', count($allLines));

        $output = sprintf(
            "日志 %s（%s）%s%s\n\n",
            $filePath,
            $this->formatFileSize($fileSize),
            $scope,
            $this->describeFilters($level, $keyword)
        );
        $output .= implode("\n", $allLines);

        if (!$complete) {
            $output .= "\n\n注意：已扫描至扫描上限（" . self::MAX_SCAN_LINES . " 行），更早内容未扫描。";
        }

        return $output;
    }

    /**
     * 过滤模式下从文件末尾渐进扫描，尽量凑足 $lines 条匹配
     *
     * @return array{lines: string[], complete: bool} complete=false 表示达到扫描上限、更早内容未扫描
     */
    private function collectFilteredTail(string $filePath, int $lines, ?string $level, ?string $keyword): array
    {
        $readLines = max($lines * 4, 400);

        while (true) {
            $content  = $this->readTailLines($filePath, $readLines);
            $allLines = array_values(array_filter(array_map('rtrim', explode("\n", $content))));
            $matched  = $this->applyFilters($allLines, $level, $keyword);

            // 读到文件开头（行数不足请求量）或匹配足够，或达到扫描上限
            $reachedStart = substr_count($content, "\n") < $readLines;

            if (count($matched) >= $lines || $reachedStart || $readLines >= self::MAX_SCAN_LINES) {
                return [
                    'lines'    => $matched,
                    'complete' => $reachedStart,
                ];
            }

            $readLines *= 2;
        }
    }

    /**
     * 应用级别与关键字过滤，返回重新索引后的匹配行
     *
     * @param string[] $allLines
     * @return string[]
     */
    private function applyFilters(array $allLines, ?string $level, ?string $keyword): array
    {
        if ($level !== null) {
            $allLines = array_filter($allLines, function (string $line) use ($level): bool {
                return stripos($line, "[{$level}]") !== false
                    || stripos($line, " {$level} ") !== false
                    || stripos($line, strtoupper($level)) !== false;
            });
        }

        if ($keyword !== null) {
            $allLines = array_filter(
                $allLines,
                static fn(string $line): bool => stripos($line, $keyword) !== false
            );
        }

        return array_values($allLines);
    }

    /**
     * 生成过滤条件描述，如 "（级别: error，关键字: upload）"
     */
    private function describeFilters(?string $level, ?string $keyword): string
    {
        $parts = [];

        if ($level !== null) {
            $parts[] = "级别: {$level}";
        }

        if ($keyword !== null) {
            $parts[] = "关键字: {$keyword}";
        }

        return $parts === [] ? '' : '（' . implode('，', $parts) . '）';
    }
}
