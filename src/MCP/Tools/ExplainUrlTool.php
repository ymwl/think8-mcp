<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Tools;

use think\App;

/**
 * URL 归因工具 - 解释一个 URL/路径会命中哪条路由、目标控制器是否存在
 *
 * 用于排查"404 到底是路由没注册，还是控制器/方法不存在被掩盖"：
 * 基于路由静态分析（与 get_routes 同源），提取参数化规则的实参，
 * 并对处理器的类/方法做存在性检查。
 */
class ExplainUrlTool implements ToolInterface
{
    /** 最多展示的命中路由数 */
    private const MAX_MATCHES = 8;

    public function __construct(
        private App $app
    ) {}

    public function getName(): string
    {
        return 'explain_url';
    }

    public function getDescription(): string
    {
        return '解释 URL/路径会命中哪条路由规则，并检查处理器类与方法是否存在（定位 404 被掩盖类问题）；支持 :param 与 <param> 参数提取。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'url' => [
                    'type'        => 'string',
                    'description' => '要解释的路径或完整 URL，如 /plugins/dchaxun/index/index 或 http://host/api/user/1?x=1（查询串会被忽略）',
                ],
                'method' => [
                    'type'        => 'string',
                    'description' => '请求方法（默认 GET），影响匹配的路由规则',
                    'enum'        => ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $params): string
    {
        $input  = trim((string)($params['url'] ?? ''));
        $method = strtoupper(trim((string)($params['method'] ?? 'GET'))) ?: 'GET';

        if ($input === '') {
            return '错误：url 参数不能为空。示例：/plugins/dchaxun/index/index';
        }

        $path = $this->normalizePath($input);

        $routes = (new RoutesTool($this->app))->collectRouteList();

        if (empty($routes)) {
            return "未找到任何路由定义（静态分析）。\n\n可能原因：route/ 与 app/*/route/ 目录为空，或路由通过其他方式动态注册。";
        }

        $matches = [];
        foreach ($routes as $route) {
            $match = $this->matchRoute($path, $method, $route);

            if ($match !== null) {
                $matches[] = ['route' => $route, 'params' => $match];

                if (count($matches) >= self::MAX_MATCHES) {
                    break;
                }
            }
        }

        $output = sprintf("URL %s（%s）\n", $path, $method);

        if ($matches === []) {
            $output .= "未命中任何已注册路由。\n";
            $output .= "\n提示：本结果基于路由文件静态分析（正则），Route::group 等动态注册可能未覆盖；"
                . "若为插件地址（/plugins/...），确认 app/{应用}/route/ 下的分发规则是否生效。";

            return $output;
        }

        $output .= sprintf("命中 %d 条路由：\n", count($matches));

        foreach ($matches as $index => $match) {
            $route = $match['route'];

            $output .= sprintf(
                "%d) %s %s  [%s]\n",
                $index + 1,
                $route['method'],
                $route['uri'],
                $route['source']
            );
            $output .= '   处理器：' . $this->checkHandler((string)$route['handler']) . "\n";

            if ($match['params'] !== []) {
                $parts = [];
                foreach ($match['params'] as $key => $value) {
                    $parts[] = "{$key}={$value}";
                }
                $output .= '   参数：' . implode(', ', $parts) . "\n";
            }
        }

        return rtrim($output, "\n");
    }

    /**
     * 从输入中提取纯路径（去域名/查询串/锚点，补前导斜杠）
     */
    private function normalizePath(string $input): string
    {
        if (preg_match('#^https?://#i', $input)) {
            $path = (string)parse_url($input, PHP_URL_PATH);
        } else {
            $path = (string)preg_split('/[?#]/', $input)[0];
        }

        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : (rtrim($path, '/') ?: '/');
    }

    /**
     * 匹配单条路由规则
     *
     * @param array<string, mixed> $route
     * @return array<string, string>|null 命中时返回参数键值对
     */
    private function matchRoute(string $path, string $method, array $route): ?array
    {
        $routeMethod = strtoupper((string)$route['method']);
        $methodHit   = in_array($routeMethod, ['ANY', '*', $method], true)
            || ($method === 'HEAD' && $routeMethod === 'GET');

        if (!$methodHit) {
            return null;
        }

        $regex = $this->buildRegex((string)$route['uri']);

        if ($regex === null || !preg_match($regex, $path, $m)) {
            return null;
        }

        $params = [];
        foreach ($m as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * 把路由规则转成正则：支持 :param、<param>、<param?>（可选）
     */
    private function buildRegex(string $uri): ?string
    {
        if ($uri === '') {
            return null;
        }

        // 占位符先抽成标记，避免被 preg_quote 转义
        $tokens = [];

        $uri = (string)preg_replace_callback('/<(\w+)\?>/', static function (array $m) use (&$tokens): string {
            $tokens[] = '(?P<' . $m[1] . '>[^/]*)';
            return "\x01" . (count($tokens) - 1) . "\x01";
        }, $uri);

        $uri = (string)preg_replace_callback('/<(\w+)>|:(\w+)/', static function (array $m) use (&$tokens): string {
            $name     = $m[1] !== '' ? $m[1] : $m[2];
            $tokens[] = '(?P<' . $name . '>[^/]+)';
            return "\x01" . (count($tokens) - 1) . "\x01";
        }, $uri);

        $quoted = preg_quote($uri, '#');

        $quoted = (string)preg_replace_callback('/\x01(\d+)\x01/', static function (array $m) use ($tokens): string {
            return $tokens[(int)$m[1]] ?? '';
        }, $quoted);

        return '#^' . $quoted . '$#';
    }

    /**
     * 处理器存在性检查（类/方法）
     */
    private function checkHandler(string $handler): string
    {
        $handler = trim($handler);

        if ($handler === '' || str_contains($handler, '(Closure)') || str_contains($handler, '(unknown)')) {
            return '闭包或未解析的处理器（无法做存在性检查）';
        }

        $class  = $handler;
        $method = '';

        if (str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
        }

        $class = '\\' . ltrim($class, '\\');

        if (!class_exists($class)) {
            return "类不存在：{$class}  ⚠ 路由已注册但目标缺失（典型的 404 掩盖来源）";
        }

        if ($method !== '' && !method_exists($class, $method)) {
            return "类存在，但方法不存在：{$class}::{$method}()  ⚠ 路由已注册但目标缺失";
        }

        return $method !== ''
            ? "类与方法均存在（{$class}::{$method}）"
            : "类存在（{$class}）";
    }
}
