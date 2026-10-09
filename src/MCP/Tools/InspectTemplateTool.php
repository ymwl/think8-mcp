<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Tools;

use think\App;
use ymwl\think8mcp\MCP\Concerns\TruncatesOutput;

/**
 * 模板编译产物工具 - 定位模板源文件与 runtime/temp 下的编译产物
 *
 * 排查"模板输出为什么不对"：直接看编译后的 PHP，
 * 重点可对比编译期替换差异（占位常量、|raw 处理等）。
 *
 * 编译产物命名规则（think-template）：
 *   {cache_path}/{cache_prefix} + md5(layout_on . layout_name . 模板绝对路径) + .{cache_suffix}
 */
class InspectTemplateTool implements ToolInterface
{
    use TruncatesOutput;

    /** 默认展示的编译产物片段长度（字符） */
    private const DEFAULT_EXCERPT = 2500;

    public function __construct(
        private App $app
    ) {}

    public function getName(): string
    {
        return 'inspect_template';
    }

    public function getDescription(): string
    {
        return '定位模板源文件与其编译产物（runtime/temp），展示编译时效性与编译后内容片段，用于排查模板编译期替换类问题（占位常量、|raw 等）。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => '模板路径，如 admin/addon/add（应用名/控制器/操作），或 admin/view/index/index.html 形式的文件路径',
                ],
                'full' => [
                    'type'        => 'boolean',
                    'description' => 'true 时输出完整编译产物（默认只展示前 ' . self::DEFAULT_EXCERPT . ' 字符）',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $params): string
    {
        $input = trim((string)($params['path'] ?? ''));
        $full  = !empty($params['full']);

        if ($input === '') {
            return '错误：path 参数不能为空。示例：admin/addon/add';
        }

        $source = $this->resolveTemplateFile($input);

        if ($source === null) {
            return "未找到模板文件：{$input}\n\n已尝试规则：app/{应用}/view/、addons/{插件}/view/、app/view/{应用}/，以及带扩展名的项目相对路径。";
        }

        $output  = sprintf(
            "模板: %s（%s，编译时间基准 %s）\n",
            $this->relativePath($source),
            $this->formatSize((int)@filesize($source)),
            date('Y-m-d H:i', (int)@filemtime($source))
        );

        [$cacheFile, $how] = $this->resolveCacheFile($source);
        $relCache          = $this->relativePath($cacheFile);

        if ($how === 'missing') {
            $output .= "编译产物: {$relCache}（未编译，首次渲染后生成）\n";
            $output .= "\n提示：模板输出异常时，对比源文件与编译产物（runtime/temp），重点排查编译期替换差异（占位常量、|raw 等）。";

            return $output;
        }

        $output .= sprintf(
            "编译产物: %s（%s，%s，%s）\n",
            $relCache,
            $how === 'scan' ? '已编译·扫描命中' : '已编译',
            $this->formatSize((int)@filesize($cacheFile)),
            date('Y-m-d H:i', (int)@filemtime($cacheFile))
        );
        $output .= '编译状态: ' . $this->checkCacheValidity($cacheFile) . "\n";

        $content = (string)@file_get_contents($cacheFile);

        if ($full) {
            $output .= "\n--- 编译产物全文 ---\n" . $this->truncateOutput($content);
        } else {
            $output .= "\n--- 编译产物片段（前 " . self::DEFAULT_EXCERPT . " 字符，full=true 查看完整）---\n";
            $output .= mb_substr($content, 0, self::DEFAULT_EXCERPT);

            if (mb_strlen($content) > self::DEFAULT_EXCERPT) {
                $output .= "\n...(已截断)";
            }
        }

        return $output;
    }

    /**
     * 解析模板源文件的绝对路径
     *
     * 支持：应用名/控制器/操作（如 admin/addon/add），或带扩展名的项目相对路径
     */
    private function resolveTemplateFile(string $input): ?string
    {
        $root  = $this->app->getRootPath();
        $sep   = DIRECTORY_SEPARATOR;
        $clean = ltrim(str_replace('\\', '/', $input), '/');

        if ($clean === '') {
            return null;
        }

        $candidates = [];
        $parts      = explode('/', $clean);

        if (count($parts) >= 2) {
            $appName = $parts[0];
            $rest    = implode('/', array_slice($parts, 1));
            $file    = str_contains($rest, '.') ? $rest : $rest . '.html';
            $rel     = str_replace('/', $sep, $file);

            $candidates[] = $root . 'app' . $sep . $appName . $sep . 'view' . $sep . $rel;
            $candidates[] = $root . 'app' . $sep . 'view' . $sep . $appName . $sep . $rel;
            $candidates[] = $root . 'addons' . $sep . $appName . $sep . 'view' . $sep . $rel;
            $candidates[] = $root . 'view' . $sep . $appName . $sep . $rel;
        }

        if (str_contains($clean, '.')) {
            // 带扩展名：兜底按项目相对路径
            $candidates[] = $root . str_replace('/', $sep, $clean);
        } else {
            // 单段名称：在所有视图目录中搜索该文件名
            $fileName = $clean . '.html';

            foreach (array_merge(
                glob($root . 'app' . $sep . '*' . $sep . 'view' . $sep . $fileName) ?: [],
                glob($root . 'addons' . $sep . '*' . $sep . 'view' . $sep . $fileName) ?: []
            ) as $found) {
                $candidates[] = $found;
            }
        }

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * 解析编译产物路径
     *
     * @return array{0: string, 1: string} [编译产物路径（未命中时返回预期路径）, md5|scan|missing]
     */
    private function resolveCacheFile(string $source): array
    {
        $cachePath = '';
        $prefix    = '';
        $suffix    = 'php';
        $layout    = 'layout';

        try {
            // 与 think-view 的 Think 驱动保持一致：md5(layout_on . layout_name . 模板绝对路径)
            $driver = new \think\view\driver\Think($this->app, (array)$this->app->config->get('view', []));

            $cachePath = (string)$driver->getConfig('cache_path');
            $prefix    = (string)$driver->getConfig('cache_prefix');
            $suffix    = ltrim((string)$driver->getConfig('cache_suffix'), '.');
            $layout    = (string)$driver->getConfig('layout_on') . (string)$driver->getConfig('layout_name');
        } catch (\Throwable) {
        }

        if ($cachePath === '') {
            $cachePath = rtrim($this->app->getRuntimePath(), '\\/') . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR;
        }

        if ($suffix === '') {
            $suffix = 'php';
        }

        if ($layout === '') {
            $layout = 'layout';
        }

        $computed = $cachePath . $prefix . md5($layout . $source) . '.' . $suffix;

        if (is_file($computed)) {
            return [$computed, 'md5'];
        }

        // 兜底：扫描编译目录，按首行 include 映射匹配源模板（与框架 checkCache 同源逻辑）
        $scanned = $this->scanCacheDirForSource($cachePath, $source);

        if ($scanned !== null) {
            return [$scanned, 'scan'];
        }

        return [$computed, 'missing'];
    }

    /**
     * 扫描编译目录，匹配首行 include 映射中包含源模板的文件
     */
    private function scanCacheDirForSource(string $cachePath, string $source): ?string
    {
        if (!is_dir($cachePath)) {
            return null;
        }

        $files = glob(rtrim($cachePath, '\\/') . DIRECTORY_SEPARATOR . '*.php') ?: [];

        if (count($files) > 3000) {
            return null; // 目录过大时跳过扫描，避免拖慢
        }

        foreach ($files as $file) {
            $map = $this->readIncludeMap($file);

            if ($map !== null && array_key_exists($source, $map)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * 读取编译文件首行的 include 映射（文件绝对路径 => 编译时的 mtime）
     *
     * @return array<string, int>|null
     */
    private function readIncludeMap(string $file): ?array
    {
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return null;
        }

        $line = fgets($handle);
        fclose($handle);

        if ($line === false || !preg_match('/\/\*(.+?)\*\//', $line, $m)) {
            return null;
        }

        $map = @unserialize($m[1]);

        return is_array($map) ? $map : null;
    }

    /**
     * 编译时效性检查（对齐框架 checkCache 的过期判定）
     */
    private function checkCacheValidity(string $cacheFile): string
    {
        $map = $this->readIncludeMap($cacheFile);

        if ($map === null || $map === []) {
            return '未知（无法解析编译头）';
        }

        foreach ($map as $path => $time) {
            if (is_file((string)$path) && filemtime((string)$path) > (int)$time) {
                return '已过期（' . basename((string)$path) . ' 在编译后有更新，下次渲染会重新编译）';
            }
        }

        return '有效（晚于所有引用模板）';
    }

    private function relativePath(string $path): string
    {
        return str_replace($this->app->getRootPath(), '', $path);
    }

    private function formatSize(int $bytes): string
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
