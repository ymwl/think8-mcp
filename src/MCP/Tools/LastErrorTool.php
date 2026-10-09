<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Tools;

use think\App;
use ymwl\think8mcp\MCP\Concerns\ReadsLogs;

/**
 * 最近错误工具 - 从 ThinkPHP 运行时日志中提取最近一条异常/错误
 */
class LastErrorTool implements ToolInterface
{
    use ReadsLogs;

    /** 最多向上追溯的字节数（4MB），防止扫描超大日志 */
    private const MAX_SCAN_BYTES = 4 * 1024 * 1024;

    /** 调用栈默认保留帧数 */
    private const DEFAULT_TRACE_DEPTH = 10;

    public function __construct(
        private App $app
    ) {}

    public function getName(): string
    {
        return 'get_last_error';
    }

    public function getDescription(): string
    {
        return '从 ThinkPHP 运行时日志中提取最近一条异常/错误（优先 *_error.log，再回退最新日志；可指定日期；调用栈默认保留前 10 帧，trace_depth=0 不限制）。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'date' => [
                    'type'        => 'string',
                    'description' => '日志日期，格式 YYYYMMDD（可选），不填则查最新日志',
                    'pattern'     => '^[0-9]{8}$',
                ],
                'trace_depth' => [
                    'type'        => 'integer',
                    'description' => '调用栈保留帧数（默认 10，0 表示不限制，上限 200）',
                    'minimum'     => 0,
                    'maximum'     => 200,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): string
    {
        $date       = isset($params['date']) ? trim((string)$params['date']) : null;
        $traceDepth = isset($params['trace_depth']) ? (int)$params['trace_depth'] : self::DEFAULT_TRACE_DEPTH;
        $traceDepth = min(max($traceDepth, 0), 200);

        if ($date !== null && $date !== '' && !preg_match('/^\d{8}$/', $date)) {
            return "错误：date 参数格式不正确，应为 YYYYMMDD，例如 \"20240516\"。";
        }

        $logPath = $this->getLogPath();

        if (!is_dir($logPath)) {
            return "日志目录不存在：{$logPath}\n\nThinkPHP 应用需要先运行才会生成日志目录。";
        }

        // 指定日期：按候选路径逐个尝试（*_error.log 优先）
        if ($date !== null && $date !== '') {
            foreach ($this->logDateCandidates($logPath, $date, true) as $candidate) {
                if (!is_file($candidate)) {
                    continue;
                }

                $result = $this->extractLastError($candidate, $traceDepth);
                if ($result['found']) {
                    return $result['text'];
                }
            }

            return "在日期 {$date} 的日志文件中未找到错误记录。\n\n已搜索以下路径：\n"
                . implode("\n", $this->logDateCandidates($logPath, $date, true));
        }

        // 未指定日期：优先专门的错误日志（如 apart_level 分离出的 *_error.log），再回退最新日志
        $errorLog = $this->findLatestLogFile($logPath, '_error.log');
        if ($errorLog !== null) {
            $result = $this->extractLastError($errorLog, $traceDepth);
            if ($result['found']) {
                return $result['text'];
            }
        }

        $logFile = $this->findLatestLogFile($logPath);

        if ($logFile === null) {
            return "日志目录 {$logPath} 中没有找到任何日志文件。";
        }

        $result = $this->extractLastError($logFile, $traceDepth);
        if ($result['found']) {
            return $result['text'];
        }

        if ($result['text'] !== '') {
            return $result['text'];
        }

        return "在日志文件 {$logFile} 中未找到错误记录。\n\n日志文件末尾内容（最后 10 行）：\n"
            . rtrim($this->readTailLines($logFile, 10), "\n");
    }

    /**
     * 从日志文件提取最近一条错误
     *
     * @return array{found: bool, text: string} found=false 时 text 为失败原因（打不开/为空等），
     *         空字符串表示"文件中未发现错误块"
     */
    private function extractLastError(string $filePath, int $traceDepth): array
    {
        if (!is_readable($filePath)) {
            return ['found' => false, 'text' => "无法读取日志文件：{$filePath}（权限不足）"];
        }

        $fileSize = @filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            return ['found' => false, 'text' => "日志文件为空：{$filePath}"];
        }

        $fp = fopen($filePath, 'r');
        if ($fp === false) {
            return ['found' => false, 'text' => "无法打开日志文件：{$filePath}"];
        }

        try {
            $content = $this->readTailContent($fp, $fileSize, self::MAX_SCAN_BYTES);
        } finally {
            fclose($fp);
        }

        if ($content === '') {
            return ['found' => false, 'text' => "日志文件内容为空：{$filePath}"];
        }

        $lines = array_values(array_filter(
            explode("\n", $content),
            fn(string $l): bool => trim($l) !== ''
        ));

        if (empty($lines)) {
            return ['found' => false, 'text' => "日志文件中没有有效内容：{$filePath}"];
        }

        $errorBlock = $this->findLastErrorBlock($lines, $traceDepth);

        if ($errorBlock === null) {
            return ['found' => false, 'text' => ''];
        }

        return [
            'found' => true,
            'text'  => "日志文件：{$filePath}\n\n" . $errorBlock,
        ];
    }

    /**
     * 在日志行数组中找最后一条错误块
     *
     * @param string[] $lines
     */
    private function findLastErrorBlock(array $lines, int $traceDepth): ?string
    {
        $totalLines   = count($lines);
        $lastErrorIdx = null;

        for ($i = $totalLines - 1; $i >= 0; $i--) {
            if ($this->isErrorLine($lines[$i])) {
                $lastErrorIdx = $i;
                break;
            }
        }

        if ($lastErrorIdx === null) {
            return null;
        }

        $blockStart = $lastErrorIdx;
        for ($i = $lastErrorIdx - 1; $i >= 0; $i--) {
            if ($this->isNewLogEntry($lines[$i])) {
                if ($this->isErrorLine($lines[$i])) {
                    $blockStart = $i;
                } else {
                    break;
                }
            } else {
                $blockStart = $i;
            }
        }

        $blockEnd = $lastErrorIdx;
        for ($i = $lastErrorIdx + 1; $i < $totalLines; $i++) {
            if ($this->isNewLogEntry($lines[$i]) && !$this->isErrorLine($lines[$i])) {
                break;
            }
            $blockEnd = $i;
        }

        $blockLines = array_slice($lines, $blockStart, $blockEnd - $blockStart + 1);
        $blockLines = $this->trimTraceFrames($blockLines, $traceDepth);
        $firstLine  = $blockLines[0] ?? '';
        $meta       = $this->parseLogMeta($firstLine);

        $result  = '';
        if ($meta['time'] !== '') {
            $result .= "发生时间：{$meta['time']}\n";
        }
        if ($meta['level'] !== '') {
            $result .= "错误级别：{$meta['level']}\n";
        }
        $result .= "\n错误详情：\n";
        $result .= implode("\n", $blockLines);
        $result .= "\n";

        return $result;
    }

    private function isErrorLine(string $line): bool
    {
        return str_contains($line, 'ERRO')
            || stripos($line, '[error]') !== false
            || str_contains($line, 'Exception')
            || str_contains($line, '#0 ');
    }

    /**
     * 截断调用栈帧（#N 行）到指定深度，0 表示不限制
     *
     * @param string[] $blockLines
     * @return string[]
     */
    private function trimTraceFrames(array $blockLines, int $traceDepth): array
    {
        if ($traceDepth <= 0) {
            return $blockLines;
        }

        $result  = [];
        $kept    = 0;
        $omitted = 0;

        foreach ($blockLines as $line) {
            if (preg_match('/^\s*#\d+\s/', $line) === 1) {
                if ($kept < $traceDepth) {
                    $result[] = $line;
                    $kept++;
                } else {
                    if ($omitted === 0) {
                        $result[] = '... [调用栈已省略后续帧（可用 trace_depth 调整，0=不限制）]';
                    }
                    $omitted++;
                }
                continue;
            }

            $result[] = $line;
        }

        return $result;
    }

    private function isNewLogEntry(string $line): bool
    {
        return str_starts_with(ltrim($line), '[');
    }

    private function parseLogMeta(string $line): array
    {
        $time  = '';
        $level = '';

        if (preg_match('/^\[([^\]]+)\]\[([^\]]+)\]/', $line, $m)) {
            $time  = $m[1];
            $level = $m[2];
        } elseif (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
            $time = $m[1];
        }

        return ['time' => $time, 'level' => $level];
    }
}
