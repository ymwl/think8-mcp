<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Tools;

use think\App;
use think\facade\Db;

/**
 * 插件状态工具 - 查看 think8-addons 插件的目录/数据库一致性
 *
 * 汇总三类信息并做交叉比对：
 *   - 磁盘：addons/{name}/ 目录、Plugin.php、info.json、menu.php、config.php、静态资源目录
 *   - 数据库：ymwl_addon 记录（版本/启用状态/安装状态/钩子）
 * 不传 name 时输出全部插件清单（含"仅目录"与"仅数据库"的异常项）。
 */
class InspectAddonTool implements ToolInterface
{
    public function __construct(
        private App $app
    ) {}

    public function getName(): string
    {
        return 'inspect_addon';
    }

    public function getDescription(): string
    {
        return '查看插件状态一览：目录文件、数据库记录、静态资源的存在性与版本一致性；不传 name 时列出全部插件。用于排查"装没装、版本对不对、资源在不在"。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'name' => [
                    'type'        => 'string',
                    'description' => '插件标识（addons/ 下的目录名，如 formcollect）；不填则列出全部插件清单',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): string
    {
        $name = trim((string)($params['name'] ?? ''));

        $dirs              = $this->scanAddonDirs();
        [$dbRows, $dbError] = $this->fetchDbRows();

        $dbByName = [];
        foreach ($dbRows as $row) {
            $dbByName[(string)($row['name'] ?? '')] = $row;
        }

        if ($name !== '') {
            return $this->renderDetail($name, $dirs, $dbByName, $dbError);
        }

        return $this->renderList($dirs, $dbByName, $dbError);
    }

    /**
     * 扫描 addons/ 目录，解析各插件的基础信息
     *
     * @return array<string, array<string, mixed>>
     */
    private function scanAddonDirs(): array
    {
        $result = [];
        $base   = $this->app->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;

        foreach (glob($base . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $addonName = basename($dir);

            if ($addonName === '' || str_starts_with($addonName, '.')) {
                continue;
            }

            $hasPlugin = is_file($dir . DIRECTORY_SEPARATOR . 'Plugin.php');
            $infoFile  = $dir . DIRECTORY_SEPARATOR . 'info.json';
            $hasInfo   = is_file($infoFile);

            if (!$hasPlugin && !$hasInfo) {
                continue;
            }

            $info = [];
            if ($hasInfo) {
                $info = json_decode((string)@file_get_contents($infoFile), true) ?: [];
            }

            $result[$addonName] = [
                'plugin'            => $hasPlugin,
                'info'              => $hasInfo,
                'menu'              => is_file($dir . DIRECTORY_SEPARATOR . 'menu.php'),
                'config'            => is_file($dir . DIRECTORY_SEPARATOR . 'config.php'),
                'static'            => is_dir($this->app->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR . $addonName),
                'title'             => (string)($info['title'] ?? ''),
                'version'           => (string)($info['version'] ?? ''),
                'require_framework' => (string)($info['require_framework'] ?? ''),
                'author'            => (string)($info['author'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * 读取 ymwl_addon 记录；表不存在或数据库不可用时报错但继续（返回原因）
     *
     * @return array{0: array<int, array<string, mixed>>, 1: ?string}
     */
    private function fetchDbRows(): array
    {
        try {
            $rows = Db::name('addon')
                ->field('name,title,version,description,status,install,is_hook,author,require_framework')
                ->select()
                ->toArray();

            return [$rows, null];
        } catch (\Throwable $e) {
            return [[], $e->getMessage()];
        }
    }

    /**
     * 清单模式：一行一个插件，标注目录/数据库/版本异常
     *
     * @param array<string, array<string, mixed>> $dirs
     * @param array<string, array<string, mixed>> $dbByName
     */
    private function renderList(array $dirs, array $dbByName, ?string $dbError): string
    {
        $all = array_values(array_unique(array_merge(array_keys($dirs), array_keys($dbByName))));
        sort($all);

        $output = sprintf("插件清单（目录 %d / 数据库 %d）\n", count($dirs), count($dbByName));

        if ($dbError !== null) {
            $output .= "⚠ 数据库读取失败：{$dbError}\n";
        }

        if ($all === []) {
            return $output . "addons/ 目录与数据库均无插件记录。";
        }

        foreach ($all as $addonName) {
            $inDir = isset($dirs[$addonName]);
            $inDb  = isset($dbByName[$addonName]);
            $dir   = $dirs[$addonName] ?? null;
            $db    = $dbByName[$addonName] ?? null;

            $line = sprintf(
                '[%s %s] %s',
                $inDir ? '目录✓' : '目录✗',
                $inDb ? 'DB✓' : 'DB✗',
                $addonName
            );

            if ($inDir && $dir !== null) {
                $line .= '  目录v' . ($dir['version'] !== '' ? $dir['version'] : '?');
            }

            if ($inDb && $db !== null) {
                $line .= '  DBv' . ((string)($db['version'] ?? '') !== '' ? (string)$db['version'] : '?');
                $line .= '  ' . $this->formatStatus($db) . '/' . $this->formatInstall($db);
            }

            if ($inDir && !$inDb) {
                $line .= '  ⚠ 目录存在但数据库无记录（未安装或残留）';
            } elseif (!$inDir && $inDb) {
                $line .= '  ⚠ 数据库有记录但目录缺失';
            } elseif ($inDir && $inDb && $dir !== null && $db !== null
                && $dir['version'] !== '' && (string)($db['version'] ?? '') !== ''
                && $dir['version'] !== (string)$db['version']) {
                $line .= '  ⚠ 版本不一致';
            }

            $output .= $line . "\n";
        }

        return rtrim($output, "\n");
    }

    /**
     * 详情模式：单插件全量信息
     *
     * @param array<string, array<string, mixed>> $dirs
     * @param array<string, array<string, mixed>> $dbByName
     */
    private function renderDetail(string $name, array $dirs, array $dbByName, ?string $dbError): string
    {
        if (!isset($dirs[$name]) && !isset($dbByName[$name])) {
            return "未找到插件 \"{$name}\"（目录与数据库均无记录）。\n\n可执行 inspect_addon（不传 name）查看全部插件清单。";
        }

        $dir = $dirs[$name] ?? null;
        $db  = $dbByName[$name] ?? null;

        $output = "插件 {$name}\n";

        if ($dir !== null) {
            $output .= sprintf(
                "[目录] addons/%s ✓ ｜ Plugin.php %s ｜ info.json %s ｜ menu.php %s ｜ config.php %s ｜ 静态资源 %s\n",
                $name,
                $dir['plugin'] ? '✓' : '✗',
                $dir['info'] ? '✓' : '✗',
                $dir['menu'] ? '✓' : '✗',
                $dir['config'] ? '✓' : '✗',
                $dir['static'] ? '✓' : '✗'
            );

            if ($dir['info']) {
                $output .= sprintf(
                    "[info.json] 标题 %s ｜ 版本 %s ｜ 框架要求 %s ｜ 作者 %s\n",
                    $dir['title'] !== '' ? $dir['title'] : '-',
                    $dir['version'] !== '' ? $dir['version'] : '-',
                    $dir['require_framework'] !== '' ? $dir['require_framework'] : '-',
                    $dir['author'] !== '' ? $dir['author'] : '-'
                );
            }
        } else {
            $output .= "[目录] addons/{$name} ✗ 不存在（数据库有记录但磁盘无目录）\n";
        }

        if ($db !== null) {
            $output .= sprintf(
                "[数据库] 版本 %s ｜ %s ｜ %s ｜ 钩子 %s ｜ 说明 %s\n",
                (string)($db['version'] ?? '') !== '' ? (string)$db['version'] : '-',
                $this->formatInstall($db),
                $this->formatStatus($db),
                (string)($db['is_hook'] ?? '0') === '1' ? '支持' : '不支持',
                mb_substr((string)($db['description'] ?? ''), 0, 80)
            );
        } else {
            $output .= "[数据库] 无 ymwl_addon 记录（目录存在但未安装/未入库）\n";
        }

        if ($dbError !== null) {
            $output .= "⚠ 数据库读取失败：{$dbError}\n";
        }

        if ($dir !== null && $db !== null
            && $dir['version'] !== '' && (string)($db['version'] ?? '') !== ''
            && $dir['version'] !== (string)$db['version']) {
            $output .= "⚠ 版本不一致：目录 {$dir['version']} ≠ 数据库 {$db['version']}\n";
        }

        return rtrim($output, "\n");
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatStatus(array $row): string
    {
        $status = (int)($row['status'] ?? 0);

        return match ($status) {
            1       => '启用',
            0       => '禁用',
            -1      => '已删除',
            default => "状态{$status}",
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatInstall(array $row): string
    {
        return (int)($row['install'] ?? 0) === 1 ? '已安装' : '未安装';
    }
}
