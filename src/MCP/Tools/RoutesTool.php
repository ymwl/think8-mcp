<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Tools;

use think\App;

/**
 * 路由工具 - 列出 ThinkPHP 应用的所有注册路由
 */
class RoutesTool implements ToolInterface
{
    /** 静态分析中发现的 Route::group 数量（分组内路由无法被当前正则完整解析） */
    private int $unparsedGroupCount = 0;

    public function __construct(
        private App $app
    ) {}

    public function getName(): string
    {
        return 'get_routes';
    }

    public function getDescription(): string
    {
        return '列出 ThinkPHP 应用的所有注册路由，包括路由规则、请求方法、控制器和中间件信息';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
            'required'   => [],
        ];
    }

    public function execute(array $params): string
    {
        $this->unparsedGroupCount = 0;

        $routes = $this->collectRoutes();

        if (empty($routes)) {
            return "未找到任何路由定义。\n\n可能的原因：\n1. route/ 目录不存在\n2. 路由文件为空\n3. 未定义任何路由规则";
        }

        $grouped = [];
        foreach ($routes as $route) {
            $grouped[$route['source']][] = $route;
        }

        $output = sprintf("路由列表（共 %d 条）\n", count($routes));

        foreach ($grouped as $source => $sourceRoutes) {
            $output .= "\n[{$source}]\n";

            foreach ($sourceRoutes as $route) {
                $middleware = !empty($route['middleware'])
                    ? '  mw:' . implode(',', $route['middleware'])
                    : '';

                $output .= "{$route['method']} {$route['uri']} -> {$route['handler']}{$middleware}\n";
            }
        }

        if ($this->unparsedGroupCount > 0) {
            $output .= sprintf(
                "\n注意：检测到 %d 处 Route::group，组内路由可能未被完整解析（本结果为静态正则分析）。\n",
                $this->unparsedGroupCount
            );
        }

        return $output;
    }

    /**
     * 收集所有路由信息
     */
    private function collectRoutes(): array
    {
        $routes = [];

        // 静态分析覆盖「根 route/ + 各应用 app/*/route/」，对多应用最完整且无副作用
        $routes = array_merge($routes, $this->getRoutesFromFiles());

        // 静态分析未解析出任何路由时，再尝试从路由对象读取（可能依赖完整应用上下文）
        if (empty($routes)) {
            $routes = array_merge($routes, $this->getRoutesFromRouter());
        }

        return $routes;
    }

    /**
     * 通过 ThinkPHP 路由对象获取路由
     */
    private function getRoutesFromRouter(): array
    {
        $routes = [];

        try {
            /** @var \think\Route $router */
            $router = $this->app->route;

            // 加载路由文件
            $routePath = $this->app->getRootPath() . 'route';
            if (is_dir($routePath)) {
                $files = glob($routePath . DIRECTORY_SEPARATOR . '*.php');
                foreach ($files as $file) {
                    try {
                        include_once $file;
                    } catch (\Throwable $e) {
                        // 部分路由文件可能依赖完整应用上下文，忽略加载错误
                    }
                }
            }

            // 获取所有路由规则
            $ruleList = $router->getRuleList();
            foreach ($ruleList as $rule) {
                $method  = strtoupper($rule['method'] ?? 'ANY');
                $uri     = $rule['rule'] ?? '/';
                $handler = $this->formatHandler($rule['route'] ?? '');
                $middleware = [];

                if (!empty($rule['option']['middleware'])) {
                    $middleware = (array)$rule['option']['middleware'];
                    $middleware = array_map(fn($m) => is_string($m) ? $m : get_class($m), $middleware);
                }

                $routes[] = [
                    'method'     => $method,
                    'uri'        => '/' . ltrim((string)$uri, '/'),
                    'handler'    => $handler,
                    'middleware' => $middleware,
                    'source'     => 'router',
                ];
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "RoutesTool::getRoutesFromRouter 错误: " . $e->getMessage() . "\n");
        }

        return $routes;
    }

    /**
     * 从路由文件静态分析路由
     */
    private function getRoutesFromFiles(): array
    {
        $routes = [];

        foreach ($this->getRouteFiles() as $label => $file) {
            $fileContents = file_get_contents($file);
            if ($fileContents === false) {
                continue;
            }

            $fileRoutes = $this->parseRouteFile($fileContents, $label);
            $routes     = array_merge($routes, $fileRoutes);
        }

        return $routes;
    }

    /**
     * 收集待分析的路由文件：根 route/ + 各应用 app/*\/route/
     *
     * @return array<string, string>  [展示用来源标签 => 文件绝对路径]
     */
    private function getRouteFiles(): array
    {
        $root = $this->app->getRootPath();
        $sep  = DIRECTORY_SEPARATOR;
        $files = [];

        // 全局路由：根 route/*.php
        foreach (glob($root . 'route' . $sep . '*.php') ?: [] as $file) {
            $files['route/' . basename($file)] = $file;
        }

        // 多应用路由：app/{应用}/route/*.php
        foreach (glob($root . 'app' . $sep . '*' . $sep . 'route' . $sep . '*.php') ?: [] as $file) {
            $relative = str_replace('\\', '/', str_replace($root, '', $file));
            $files[$relative] = $file;
        }

        return $files;
    }

    /**
     * 解析路由文件内容（正则静态分析）
     */
    private function parseRouteFile(string $content, string $filename): array
    {
        $routes = [];

        // 统计 Route::group：分组内路由无法被当前正则静态解析，仅用于输出完整性提示
        if (preg_match_all('/Route\s*::\s*group\s*\(/i', $content, $groupMatches) > 0) {
            $this->unparsedGroupCount += count($groupMatches[0]);
        }

        // 匹配常见路由定义模式
        // Route::get('/path', 'Controller/action')
        // Route::post('/path', [Controller::class, 'method'])
        $pattern = '/Route\s*::\s*(get|post|put|patch|delete|options|any|rule|resource)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([^)]+)\)/i';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $method  = strtoupper($match[1]);
                $uri     = '/' . ltrim($match[2], '/');
                $handler = trim(preg_replace('/\s+/', ' ', $match[3]));

                // 清理 handler 字符串
                $handler = preg_replace('/[\'"\\[\\]\\s]/', '', $handler);
                $handler = str_replace(['::class,', '::class'], '', $handler);

                $routes[] = [
                    'method'     => $method === 'RULE' ? 'ANY' : $method,
                    'uri'        => $uri,
                    'handler'    => $handler ?: '(closure)',
                    'middleware' => [],
                    'source'     => $filename,
                ];
            }
        }

        // 匹配 resource 路由
        $resourcePattern = '/Route\s*::\s*resource\s*\(\s*[\'"]([^\'"]+)[\'"]/i';
        if (preg_match_all($resourcePattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $resource = $match[1];
                $resourceRoutes = [
                    ['GET',    "/{$resource}",           'index'],
                    ['POST',   "/{$resource}",           'save'],
                    ['GET',    "/{$resource}/:id",       'read'],
                    ['PUT',    "/{$resource}/:id",       'update'],
                    ['DELETE', "/{$resource}/:id",       'delete'],
                    ['GET',    "/{$resource}/create",    'create'],
                    ['GET',    "/{$resource}/:id/edit",  'edit'],
                ];

                foreach ($resourceRoutes as [$method, $uri, $action]) {
                    $routes[] = [
                        'method'     => $method,
                        'uri'        => $uri,
                        'handler'    => "{$resource}@{$action}",
                        'middleware' => [],
                        'source'     => $filename,
                    ];
                }
            }
        }

        return $routes;
    }

    /**
     * 格式化路由处理器为可读字符串
     */
    private function formatHandler(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2) {
            $class  = is_string($handler[0]) ? $handler[0] : get_class($handler[0]);
            $method = $handler[1];
            return "{$class}@{$method}";
        }

        if ($handler instanceof \Closure) {
            return '(Closure)';
        }

        return '(unknown)';
    }
}
