# Think8 MCP

为 ThinkPHP 8.x 项目提供 MCP (Model Context Protocol) Server 能力，让 AI 工具（Claude Code、Cursor、Codex 等）能够理解并操作你的 ThinkPHP 项目。

> 本包基于 `laravel-boost` / `thinkphp-boost` 的设计思路重写，归属 ymwl 生态（命名空间 `ymwl\think8mcp`），并针对 PHP 8.0、多应用路由、SQL 安全与自定义工具扩展做了增强。

---

## 特性

- **纯 PHP 实现**，不依赖 Node.js，通过 stdio 传输 JSON-RPC 2.0
- **自动注册**，ThinkPHP 8.x 通过 `extra.think.services` 发现服务提供者，仅注册 console 命令，不注册任何 HTTP 路由或中间件，**对线上 web 请求零影响**
- **12 个内置工具**：路由、表结构、日志、配置、错误、SQL、PHP 执行等
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

AI 工具连接后可使用以下 12 个 MCP 工具（均可在 `config/mcp.php` 的 `tools` 段单独开关）：

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

### `execute_sql` 安全校验

仅允许只读查询，并显式拦截以下内容，防止绕过：

- 非 `SELECT` / `SHOW` / `DESCRIBE` / `EXPLAIN` 语句
- `INTO OUTFILE`、`INTO DUMPFILE`、`LOAD_FILE`、`LOAD DATA`
- `GET_LOCK`、`BENCHMARK(`
- 多语句（去除首尾后仍含 `;`）
- SQL 注释（`/* */`、`--`、`#`）会被剥离后再校验

### `run_think_command` 安全限制

默认禁止：`serve`、`clear`、`optimize`、`build`、`mcp:serve`，可在 `config/mcp.php` 的 `commands.forbidden` 中扩展。

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
    ],

    // 自定义工具类（须实现 ymwl\think8mcp\MCP\Tools\ToolInterface）
    'custom_tools' => [],

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

---

## License

MIT
