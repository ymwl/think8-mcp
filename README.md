# Think8 MCP

为 ThinkPHP 8.x 项目提供 MCP (Model Context Protocol) Server 能力，让 AI 工具（Claude Code、Cursor、Codex 等）能够理解并操作你的 ThinkPHP 项目。

> 本包基于 `laravel-boost` / `thinkphp-boost` 的设计思路重写，归属 ymwl 生态（命名空间 `ymwl\think8mcp`），并针对 PHP 8.0、多应用路由、SQL 安全与自定义工具扩展做了增强。

---

## 特性

- **纯 PHP 实现**，不依赖 Node.js，通过 stdio 传输 JSON-RPC 2.0
- **自动注册**，ThinkPHP 8.x 通过 `extra.think.services` 发现服务提供者，仅注册 console 命令，不注册任何 HTTP 路由或中间件，**对线上 web 请求零影响**
- **18 个内置工具**：路由、表结构、日志、配置、错误、SQL、PHP 执行、日志摘要、HTTP 探测、URL 归因、插件体检、单表备份、模板编译产物等
- **Skills / Guidelines / Prompts** 扩展机制
- **自定义工具扩展点**：宿主项目可在配置中登记自己的 MCP 工具类
- **MCP 协议版本**：2025-11-25（兼容 2025-06-18、2025-03-26、2024-11-05）

---

## 环境要求

- PHP **8.0+**
- ThinkPHP **8.x**

---

## 安装

### 1. 通过 Composer 安装

```bash
composer require ymwl/think8-mcp
```

### 2. 发布配置文件（可选）

配置文件位于包内的 `config/mcp.php`，ThinkPHP 会自动加载。如需自定义，复制到项目 `config/` 目录（项目配置优先）：

```bash
cp vendor/ymwl/think8-mcp/config/mcp.php config/mcp.php
```

或使用内置命令发布：

```bash
php think mcp:install
```

### 3. 验证安装

```bash
php think list
# 应能看到：mcp:serve  Start the Think8 MCP MCP Server
```

---

## 配置 AI 工具的 MCP 连接

在 ThinkPHP **项目根目录**创建对应配置文件（也可直接运行 `php think mcp:install` 自动生成）。

### Claude Code（`.mcp.json`）

```json
{
  "mcpServers": {
    "think8-mcp": {
      "command": "php",
      "args": ["think", "mcp:serve"]
    }
  }
}
```

### Cursor（`.cursor/mcp.json`）

```json
{
  "mcpServers": {
    "think8-mcp": {
      "command": "php",
      "args": ["think", "mcp:serve"]
    }
  }
}
```

### Codex（`.codex/mcp.json`）

```json
{
  "mcpServers": {
    "think8-mcp": {
      "command": "php",
      "args": ["think", "mcp:serve"]
    }
  }
}
```

### 开启调试模式

在 `args` 中添加 `--debug`，调试信息输出到 STDERR，不影响 MCP 通信：

```json
{ "args": ["think", "mcp:serve", "--debug"] }
```

---

## CLI 命令

| 命令 | 说明 |
| --- | --- |
| `php think mcp:serve` | 启动 MCP Server（stdio 常驻进程） |
| `php think mcp:install` | 检测并安装 AI 工具的 MCP 配置、发布配置文件 |
| `php think mcp:update` | 更新已安装的 Skills |
| `php think mcp:add-skill` | 添加 Skill |
| `php think mcp:list-skills` | 列出已安装的 Skills |

---

## 可用工具列表

AI 工具连接后可使用以下 18 个 MCP 工具（均可在 `config/mcp.php` 的 `tools` 段单独开关）：

| 工具名 | 说明 |
| --- | --- |
| `get_app_info` | 获取应用基本信息（框架版本、PHP 版本、应用目录等） |
| `get_routes` | 列出所有注册路由（支持多应用，扫描根 `route/` 与 `app/*/route/`） |
| `get_database_schema` | 查询数据库表结构（字段、类型、可空、默认值、注释） |
| `database_connections` | 查看已配置的数据库连接 |
| `run_think_command` | 执行 `php think` 命令（内置黑名单保护） |
| `get_logs` | 读取运行时日志（支持按日期、级别、行数过滤） |
| `get_last_error` | 读取最近一次错误 |
| `get_config` | 查看应用配置项（敏感项会脱敏） |
| `execute_sql` | 执行只读 SQL（含安全校验，见下文） |
| `run_php` | 执行 PHP 代码片段（高危，默认开启，建议生产关闭） |
| `format_code` | 使用 php-cs-fixer 格式化代码（需已安装） |
| `get_absolute_url` | 生成指定路由的绝对 URL |
| `get_log_summary` | 日志全景摘要：按级别计数 + Top 错误/警告聚合（次数、首末时间），跨常规与 `*_error` 日志 |
| `probe_http` | 发起真实 HTTP 请求探测本机站点（host 白名单，见下文），验证路由/伪静态/验证码/接口 |
| `explain_url` | 解释 URL 命中的路由规则，并检查处理器类/方法是否存在（定位 404 掩盖类问题） |
| `inspect_addon` | 插件状态一览：目录/数据库/静态资源交叉比对，标注版本不一致与缺失 |
| `db_backup` | 按项目数据库配置导出单表（结构+数据）到 `runtime/backup/`，高危操作前的备份动作 |
| `inspect_template` | 定位模板源文件与编译产物（`runtime/temp`），含编译时效性判定与内容片段查看 |

### `execute_sql` 安全校验

仅允许只读查询，并显式拦截以下内容，防止绕过：

- 非 `SELECT` / `SHOW` / `DESCRIBE` / `EXPLAIN` 语句
- `INTO OUTFILE`、`INTO DUMPFILE`、`LOAD_FILE`、`LOAD DATA`
- `GET_LOCK`、`BENCHMARK(`
- 多语句（去除首尾后仍含 `;`）
- SQL 注释（`/* */`、`--`、`#`）会被剥离后再校验

### `run_think_command` 安全限制

默认禁止：`serve`、`clear`、`optimize`、`build`、`mcp:serve`，可在 `config/mcp.php` 的 `commands.forbidden` 中扩展。

### `probe_http` 白名单限制

仅允许请求白名单内的 host：回环地址（`127.0.0.1` / `localhost` / `::1`）与**解析到回环地址的本机域名**（如 hosts 中指向 `127.0.0.1` 的开发域名）始终放行；其他域名需在 `config/mcp.php` 的 `probe.allowed_hosts` 中追加。仅支持 `GET` / `HEAD` / `POST`，默认不跟随重定向，响应体截断返回，`Set-Cookie` 仅展示名称。

---

## 配置文件说明

`config/mcp.php`：

```php
return [
    // 内置工具开关
    'tools' => [
        'app_info'         => true,
        'routes'           => true,
        'schema'           => true,
        'db_connections'   => true,
        'commands'         => true,
        'logs'             => true,
        'last_error'       => true,
        'config'           => true,
        'execute_sql'      => true,
        'run_php'          => true,
        'format_code'      => true,
        'get_absolute_url' => true,
        'log_summary'      => true,
        'probe_http'       => true,
        'explain_url'      => true,
        'inspect_addon'    => true,
        'db_backup'        => true,
        'inspect_template' => true,
    ],

    // 自定义工具类（须实现 ymwl\think8mcp\MCP\Tools\ToolInterface）
    'custom_tools' => [],

    // HTTP 探测配置（probe_http 工具）
    'probe' => [
        'allowed_hosts' => [],   // 追加白名单 host（回环地址与解析到回环的本机域名始终放行）
        'timeout'       => 5,    // 请求总超时秒数
        'max_body'      => 3000, // 响应体最大展示字符数
    ],

    // 日志配置
    'logs' => [
        'path'      => runtime_path('log'),
        'max_lines' => 500,
    ],

    // 命令黑名单
    'commands' => [
        'forbidden' => ['serve', 'clear', 'optimize', 'build', 'mcp:serve'],
    ],

    // 高危工具开关（生产环境建议关闭）
    'security' => [
        'allow_execute_sql' => true,
        'allow_run_php'     => true,
    ],
];
```

---

## 自定义工具（扩展点）

在 `config/mcp.php` 的 `custom_tools` 中登记工具类即可，无需修改本包代码：

```php
'custom_tools' => [
    \app\mcp\AddonInfoTool::class,
],
```

要求：

- 实现 `ymwl\think8mcp\MCP\Tools\ToolInterface`
- 构造函数接收 `\think\App` 实例

```php
namespace app\mcp;

use think\App;
use ymwl\think8mcp\MCP\Tools\ToolInterface;

class AddonInfoTool implements ToolInterface
{
    public function __construct(private App $app) {}

    public function getName(): string
    {
        return 'get_addon_info';
    }

    // ... 其余接口方法
}
```

---

## Skills / Guidelines / Prompts

- **Guidelines**：项目根目录 `.ai/guidelines/*.md` 会被合并进 MCP 的 instructions
- **Skills**：项目根目录 `.ai/skills/{name}/SKILL.md` 会注册为 MCP resources
- **Prompts**：支持从约定目录加载提示词模板

---

## 安全说明

1. `execute_sql` 能读取全库数据，`run_php` 可执行任意 PHP 代码——两者均属开发后门，**生产或有敏感数据的环境请将 `security` 段对应开关设为 `false`**。
2. `mcp:serve` 是常驻进程，由 AI 工具负责其生命周期，退出 AI 工具时进程随之终止。
3. MCP 协议要求 JSON-RPC 响应必须从 STDOUT 输出，任何日志/调试信息均写入 STDERR，本包严格遵守此规则。
4. `probe_http` 会向白名单内的本机地址发起真实请求（默认仅回环地址与本机域名）；`db_backup` 仅将备份文件写入项目 `runtime/backup/` 目录，不修改数据库。

---

## License

MIT
