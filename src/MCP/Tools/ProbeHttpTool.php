<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Tools;

use think\App;

/**
 * HTTP 探测工具 - 对本机开发站点发起真实请求，验证路由/伪静态/验证码/接口可达性
 *
 * 安全约束：
 *   - 仅允许 http/https，且 host 必须命中白名单（回环地址、配置的 allowed_hosts、
 *     app_host，或解析到回环地址的本机开发域名）；
 *   - 默认不跟随重定向；响应体截断返回；Set-Cookie 仅展示名称。
 */
class ProbeHttpTool implements ToolInterface
{
    private const DEFAULT_TIMEOUT  = 5;
    private const DEFAULT_MAX_BODY = 3000;
    private const MAX_REDIRECTS    = 3;

    /** 允许的请求方法（不放开 PUT/DELETE 等写语义方法） */
    private const ALLOWED_METHODS = ['GET', 'HEAD', 'POST'];

    public function __construct(
        private App $app
    ) {}

    public function getName(): string
    {
        return 'probe_http';
    }

    public function getDescription(): string
    {
        return '对本机开发站点发起真实 HTTP 请求（host 白名单限制，默认仅回环地址与本机域名），返回状态码、关键响应头与响应体（自动截断）。用于验证路由、伪静态、验证码、301 与接口连通性。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'url' => [
                    'type'        => 'string',
                    'description' => '请求地址：相对路径（如 /captcha）或完整 URL（http://...）。相对路径基于站点基址（app_host / APP_URL，缺省 http://127.0.0.1）。',
                ],
                'method' => [
                    'type'        => 'string',
                    'description' => '请求方法，仅支持 GET / HEAD / POST（默认 GET）',
                    'enum'        => self::ALLOWED_METHODS,
                ],
                'data' => [
                    'type'        => 'object',
                    'description' => 'POST 提交的数据（可选，键值对）',
                ],
                'as_json' => [
                    'type'        => 'boolean',
                    'description' => 'POST 时以 JSON 发送 data（默认 false，即表单编码）',
                ],
                'follow' => [
                    'type'        => 'boolean',
                    'description' => '是否跟随重定向（默认 false，直接展示 301/302 与 Location）',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $params): string
    {
        $input  = trim((string)($params['url'] ?? ''));
        $method = strtoupper(trim((string)($params['method'] ?? 'GET')));
        $data   = is_array($params['data'] ?? null) ? $params['data'] : [];
        $asJson = !empty($params['as_json']);
        $follow = !empty($params['follow']);

        if ($input === '') {
            return '错误：url 参数不能为空。示例：/captcha 或 http://127.0.0.1/';
        }

        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return '错误：method 仅支持 ' . implode(' / ', self::ALLOWED_METHODS) . "。";
        }

        [$url, $error] = $this->resolveRequestUrl($input);
        if ($error !== null) {
            return $error;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '安全限制：仅支持 http/https 协议。';
        }

        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return "错误：无法解析 URL 的 host：{$url}";
        }

        if (!$this->isHostAllowed($host)) {
            return "安全限制：host \"{$host}\" 不在探测白名单内。\n\n允许：回环地址（127.0.0.1/localhost/::1）、解析到回环的本机域名，"
                . "或 config/mcp.php 中 mcp.probe.allowed_hosts 追加的 host。\n当前白名单：" . implode('、', $this->getAllowedHosts());
        }

        $timeout = (int)$this->getConfig('timeout', self::DEFAULT_TIMEOUT) ?: self::DEFAULT_TIMEOUT;
        $maxBody = (int)$this->getConfig('max_body', self::DEFAULT_MAX_BODY) ?: self::DEFAULT_MAX_BODY;

        return function_exists('curl_init')
            ? $this->runCurl($method, $url, $data, $asJson, $follow, $timeout, $maxBody)
            : $this->runStream($method, $url, $data, $asJson, $timeout, $maxBody);
    }

    /**
     * 相对路径 → 完整 URL（基于 app_host / APP_URL / APP_HOST，缺省回环地址）
     *
     * @return array{0: string, 1: ?string} [URL, 错误信息或 null]
     */
    private function resolveRequestUrl(string $input): array
    {
        if (preg_match('#^https?://#i', $input)) {
            return [$input, null];
        }

        $base = $this->resolveBaseUrl();

        return [rtrim($base, '/') . '/' . ltrim($input, '/'), null];
    }

    private function resolveBaseUrl(): string
    {
        try {
            $host = (string)$this->app->config->get('app.app_host', '');
            if ($host !== '') {
                return str_starts_with($host, 'http') ? $host : 'http://' . $host;
            }
        } catch (\Throwable) {
        }

        foreach (['APP_URL', 'APP_HOST'] as $key) {
            $value = $this->getEnvVar($key);
            if ($value !== null && $value !== '') {
                return str_starts_with($value, 'http') ? $value : 'http://' . $value;
            }
        }

        return 'http://127.0.0.1';
    }

    /**
     * 允许的 host 白名单：回环地址 + app_host + 配置追加
     *
     * @return string[]
     */
    private function getAllowedHosts(): array
    {
        $hosts = ['127.0.0.1', 'localhost', '::1'];

        try {
            $configured = (array)$this->app->config->get('mcp.probe.allowed_hosts', []);
            $hosts      = array_merge($hosts, $configured);
        } catch (\Throwable) {
        }

        $host = parse_url($this->resolveBaseUrl(), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $hosts[] = $host;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn($h): string => strtolower(trim((string)$h)),
            $hosts
        ))));
    }

    private function isHostAllowed(string $host): bool
    {
        $host = strtolower($host);

        if (in_array($host, $this->getAllowedHosts(), true)) {
            return true;
        }

        // 允许解析到回环地址的本机开发域名（如 hosts 中指向 127.0.0.1 的站点域名）
        $ip = gethostbyname($host);

        return $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)
            && (str_starts_with($ip, '127.') || $ip === '::1');
    }

    private function getConfig(string $key, int $default): int
    {
        try {
            return (int)$this->app->config->get('mcp.probe.' . $key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function runCurl(string $method, string $url, array $data, bool $asJson, bool $follow, int $timeout, int $maxBody): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return '错误：curl 初始化失败。';
        }

        [$headers, $body] = $this->buildRequestPayload($method, $data, $asJson);
        $respHeaders      = [];

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS      => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$respHeaders): int {
                $respHeaders[] = trim($header);
                return strlen($header);
            },
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $start  = microtime(true);
        $result = curl_exec($ch);
        $ms     = (int)round((microtime(true) - $start) * 1000);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $effUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($result === false && $errNo !== 0) {
            return "请求失败：{$errMsg}（curl 错误码 {$errNo}，耗时 {$ms} ms）\n\n"
                . '提示：确认本地站点已启动、域名/端口可访问；可用完整 URL 测试（如 http://127.0.0.1/）。';
        }

        return $this->formatHttpResult(
            $method,
            $effUrl !== '' ? $effUrl : $url,
            $status,
            $respHeaders,
            is_string($result) ? $result : '',
            $ms,
            $maxBody,
            $follow
        );
    }

    /**
     * 无 curl 扩展时的降级实现（stream context）
     */
    private function runStream(string $method, string $url, array $data, bool $asJson, int $timeout, int $maxBody): string
    {
        [$headers, $body] = $this->buildRequestPayload($method, $data, $asJson);

        $context = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headers),
                'content'         => $body ?? '',
                'timeout'         => $timeout,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
        ]);

        $start  = microtime(true);
        $result = @file_get_contents($url, false, $context);
        $ms     = (int)round((microtime(true) - $start) * 1000);

        if ($result === false) {
            return "请求失败：无法连接（耗时 {$ms} ms）。\n\n提示：确认本地站点已启动、域名/端口可访问。";
        }

        $rawHeaders = isset($http_response_header) ? (array)$http_response_header : [];
        $status     = 0;
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            }
        }

        return $this->formatHttpResult($method, $url, $status, $rawHeaders, $result, $ms, $maxBody, false);
    }

    /**
     * 组装请求头与请求体
     *
     * @return array{0: string[], 1: ?string}
     */
    private function buildRequestPayload(string $method, array $data, bool $asJson): array
    {
        $headers = ['Accept: */*'];
        $body    = null;

        if ($method === 'POST' && $data !== []) {
            if ($asJson) {
                $body      = (string)json_encode($data, JSON_UNESCAPED_UNICODE);
                $headers[] = 'Content-Type: application/json';
            } else {
                $body      = http_build_query($data);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        return [$headers, $body];
    }

    /**
     * 紧凑格式化响应：状态行 + 关键响应头 + 截断的响应体
     *
     * @param string[] $rawHeaders
     */
    private function formatHttpResult(string $method, string $url, int $status, array $rawHeaders, string $body, int $ms, int $maxBody, bool $follow): string
    {
        $output = sprintf("%s %s → %s（%d ms）\n", $method, $url, $status > 0 ? (string)$status : '无响应', $ms);

        $interest = ['content-type', 'content-length', 'location', 'x-powered-by', 'server'];
        $cookies  = [];

        foreach ($rawHeaders as $line) {
            if ($line === '' || str_starts_with($line, 'HTTP/')) {
                continue;
            }

            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }

            $name  = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));

            if ($name === 'set-cookie') {
                $cookies[] = explode('=', explode(';', $value)[0])[0];
                continue;
            }

            if (in_array($name, $interest, true)) {
                $output .= "{$name}: {$value}\n";
            }
        }

        if ($cookies !== []) {
            $output .= 'set-cookie: ' . implode(', ', array_unique($cookies)) . "（仅列名称）\n";
        }

        if (in_array($status, [301, 302, 303, 307, 308], true) && !$follow) {
            $output .= "（重定向未跟随，follow=true 可跟随）\n";
        }

        if ($method !== 'HEAD' && $body !== '') {
            $length = mb_strlen($body);
            $output .= "\nBody" . ($length > $maxBody ? "（前 {$maxBody} 字符，共 {$length}）" : '') . ":\n";
            $output .= mb_substr($body, 0, $maxBody);

            if ($length > $maxBody) {
                $output .= "\n...(已截断)";
            }
        }

        return $output;
    }

    /**
     * 从 .env 读取单个键值（仅取 APP_URL / APP_HOST）
     */
    private function getEnvVar(string $key): ?string
    {
        try {
            $envFile = $this->app->getRootPath() . '.env';
            if (!is_file($envFile)) {
                return null;
            }

            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                return null;
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$envKey, $envVal] = explode('=', $line, 2);
                if (trim($envKey) === $key) {
                    return trim($envVal, " \t\n\r\0\x0B\"'");
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
