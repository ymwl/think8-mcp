<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Concerns;

use think\App;

/**
 * 日志文件读取公共逻辑，供 LogsTool 和 LastErrorTool 复用
 */
trait ReadsLogs
{
    private function getLogPath(): string
    {
        try {
            /** @var App $app */
            $configured = $this->app->config->get('mcp.logs.path');
            if ($configured && is_string($configured)) {
                return rtrim($configured, DIRECTORY_SEPARATOR);
            }
        } catch (\Throwable) {
        }

        return rtrim($this->app->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'log';
    }

    /**
     * 进程内"最新日志文件"缓存（TTL 60 秒），避免同一会话反复递归扫描日志目录
     *
     * @var array<string, array{time: int, file: string}>
     */
    private static array $latestLogCache = [];

    /**
     * 找最新日志文件（按文件修改时间）
     *
     * @param string      $logPath 日志目录
     * @param string|null $suffix  仅匹配该后缀（如 "_error.log"），null 表示任意 .log
     */
    private function findLatestLogFile(string $logPath, ?string $suffix = null): ?string
    {
        $cacheKey = $logPath . '|' . ($suffix ?? '');
        $cached   = self::$latestLogCache[$cacheKey] ?? null;

        if ($cached !== null && (time() - $cached['time']) < 60 && is_file($cached['file'])) {
            return $cached['file'];
        }

        $latestFile = null;
        $latestTime = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($logPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'log') {
                    continue;
                }

                if ($suffix !== null && !str_ends_with($file->getBasename(), $suffix)) {
                    continue;
                }

                $mtime = $file->getMTime();
                if ($mtime > $latestTime) {
                    $latestTime = $mtime;
                    $latestFile = $file->getPathname();
                }
            }
        } catch (\Throwable) {
        }

        if ($latestFile !== null) {
            self::$latestLogCache[$cacheKey] = ['time' => time(), 'file' => $latestFile];
        }

        return $latestFile;
    }

    /**
     * 生成按日期的候选日志路径
     *
     * @param bool $preferErrorLog true 时 *_error.log 候选排在前面
     * @return string[]
     */
    private function logDateCandidates(string $logPath, string $date, bool $preferErrorLog = false): array
    {
        $year  = substr($date, 0, 4);
        $month = substr($date, 4, 2);
        $day   = substr($date, 6, 2);
        $sep   = DIRECTORY_SEPARATOR;

        $errorCandidates = [
            "{$logPath}{$sep}{$date}_error.log",
            "{$logPath}{$sep}{$year}{$month}{$sep}{$day}_error.log",
        ];

        $normalCandidates = [
            "{$logPath}{$sep}{$year}{$month}{$sep}{$day}.log",
            "{$logPath}{$sep}{$year}-{$month}{$sep}{$day}.log",
            "{$logPath}{$sep}{$date}.log",
            "{$logPath}{$sep}{$year}{$sep}{$month}{$sep}{$day}.log",
        ];

        return $preferErrorLog
            ? array_merge($errorCandidates, $normalCandidates)
            : array_merge($normalCandidates, $errorCandidates);
    }

    /**
     * 按日期（YYYYMMDD）解析日志文件路径，未找到返回 null
     */
    private function findLogFileByDate(string $logPath, string $date, bool $preferErrorLog = false): ?string
    {
        foreach ($this->logDateCandidates($logPath, $date, $preferErrorLog) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * 从文件末尾读取最多 $maxBytes 字节内容，自动跳过首个不完整行
     *
     * @param resource $fp
     */
    private function readTailContent($fp, int $fileSize, int $maxBytes): string
    {
        $scanLimit = min($fileSize, $maxBytes);
        $startPos  = $fileSize - $scanLimit;

        fseek($fp, $startPos);
        $buffer = fread($fp, $scanLimit);

        if ($buffer === false) {
            return '';
        }

        if ($startPos > 0) {
            $firstNewline = strpos($buffer, "\n");
            if ($firstNewline !== false) {
                $buffer = substr($buffer, $firstNewline + 1);
            }
        }

        return $buffer;
    }

    /**
     * 从文件末尾反向读取指定行数（逐块读取，避免加载整个文件）
     */
    private function readTailLines(string $filePath, int $lines): string
    {
        $fp = fopen($filePath, 'r');
        if ($fp === false) {
            return '';
        }

        $buffer     = '';
        $chunkSize  = 8192;
        $linesFound = 0;

        fseek($fp, 0, SEEK_END);
        $position = ftell($fp);

        while ($position > 0 && $linesFound < $lines) {
            $chunkSize = min($chunkSize, $position);
            $position -= $chunkSize;
            fseek($fp, $position);

            $chunk      = fread($fp, $chunkSize);
            $buffer     = $chunk . $buffer;
            $linesFound = substr_count($buffer, "\n");
        }

        fclose($fp);

        if ($position > 0) {
            $firstNewline = strpos($buffer, "\n");
            if ($firstNewline !== false) {
                $buffer = substr($buffer, $firstNewline + 1);
            }
        }

        return $buffer;
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
