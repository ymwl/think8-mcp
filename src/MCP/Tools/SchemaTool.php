<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Tools;

use think\App;

/**
 * 数据库 Schema 工具 - 查询数据库表结构
 */
class SchemaTool implements ToolInterface
{
    public function __construct(
        private App $app
    ) {}

    public function getName(): string
    {
        return 'get_database_schema';
    }

    public function getDescription(): string
    {
        return '查询数据库表：默认只返回表名清单（可用 pattern 按子串过滤）；指定 table 返回该表详细结构；指定 column 反查包含该字段的表。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'table' => [
                    'type'        => 'string',
                    'description' => '要查看详细结构的表名（可选）。不填则只返回表名清单。',
                ],
                'pattern' => [
                    'type'        => 'string',
                    'description' => '按表名子串过滤（不区分大小写），返回匹配的表名清单。仅在未指定 table 时生效。',
                ],
                'column' => [
                    'type'        => 'string',
                    'description' => '反查包含指定字段名（精确匹配）的表，适合定位"某字段在哪些表里"。',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): string
    {
        $targetTable  = isset($params['table']) ? trim((string)$params['table']) : null;
        $pattern      = isset($params['pattern']) ? trim((string)$params['pattern']) : null;
        $targetColumn = isset($params['column']) ? trim((string)$params['column']) : null;

        try {
            $pdo = $this->getPdo();
        } catch (\Throwable $e) {
            return "无法连接到数据库：{$e->getMessage()}\n\n请检查 .env 或 config/database.php 中的数据库配置。";
        }

        try {
            if ($targetTable !== null && $targetTable !== '') {
                return $this->describeTable($pdo, $targetTable);
            }

            if ($targetColumn !== null && $targetColumn !== '') {
                return $this->findColumn($pdo, $targetColumn);
            }

            return $this->listTables($pdo, $pattern);
        } catch (\Throwable $e) {
            return "查询数据库结构时出错：{$e->getMessage()}";
        }
    }

    /**
     * 获取 PDO 连接
     */
    private function getPdo(): \PDO
    {
        // 尝试从 ThinkPHP 数据库配置获取连接参数
        $config = $this->getDatabaseConfig();

        $dsn      = $config['dsn'] ?? null;
        $hostname = $config['hostname'] ?? $config['host'] ?? '127.0.0.1';
        $database = $config['database'] ?? $config['dbname'] ?? '';
        $username = $config['username'] ?? $config['user'] ?? 'root';
        $password = $config['password'] ?? $config['pass'] ?? '';
        $hostport = $config['hostport'] ?? $config['port'] ?? 3306;
        $charset  = $config['charset'] ?? 'utf8mb4';

        if (empty($dsn)) {
            $dsn = "mysql:host={$hostname};port={$hostport};dbname={$database};charset={$charset}";
        }

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT            => 5,
        ];

        return new \PDO($dsn, $username, $password, $options);
    }

    /**
     * 获取数据库配置
     */
    private function getDatabaseConfig(): array
    {
        try {
            $config = $this->app->config->get('database');

            if (!empty($config)) {
                // ThinkPHP 8.x 默认连接配置
                $defaultConn = $config['default'] ?? 'mysql';
                $connections = $config['connections'] ?? [];

                if (!empty($connections[$defaultConn])) {
                    return $connections[$defaultConn];
                }

                // 兼容旧格式
                if (isset($config['hostname']) || isset($config['host'])) {
                    return $config;
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "SchemaTool: 读取数据库配置失败: " . $e->getMessage() . "\n");
        }

        // 尝试读取 .env 文件
        return $this->getConfigFromEnv();
    }

    /**
     * 从 .env 文件读取数据库配置
     */
    private function getConfigFromEnv(): array
    {
        $envFile = $this->app->getRootPath() . '.env';
        $config  = [];

        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$key, $value]  = explode('=', $line, 2);
                $key            = trim($key);
                $value          = trim($value, " \t\n\r\0\x0B\"'");

                $map = [
                    'DB_HOST'     => 'hostname',
                    'DB_PORT'     => 'hostport',
                    'DB_DATABASE' => 'database',
                    'DB_USERNAME' => 'username',
                    'DB_PASSWORD' => 'password',
                    'DB_CHARSET'  => 'charset',
                ];

                if (isset($map[$key])) {
                    $config[$map[$key]] = $value;
                }
            }
        }

        return $config;
    }

    /**
     * 反查包含指定字段名的表（精确匹配字段名）
     */
    private function findColumn(\PDO $pdo, string $column): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            return "字段名格式不合法：{$column}";
        }

        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?
             ORDER BY TABLE_NAME'
        );
        $stmt->execute([$column]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return "没有任何表包含字段 \"{$column}\"（精确匹配）。";
        }

        $output = sprintf("包含字段 \"%s\" 的表（%d 张）：\n", $column, count($rows));

        foreach ($rows as $row) {
            $line = $row['TABLE_NAME'] . '  ' . $row['COLUMN_TYPE']
                . ($row['IS_NULLABLE'] === 'YES' ? '  NULL' : '  NOT NULL');

            if (!empty($row['COLUMN_COMMENT'])) {
                $line .= '  ' . $row['COLUMN_COMMENT'];
            }

            $output .= $line . "\n";
        }

        return rtrim($output, "\n");
    }

    /**
     * 返回表名清单（默认输出，紧凑一行一表，避免整库结构灌入上下文）
     */
    private function listTables(\PDO $pdo, ?string $pattern): string
    {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($tables)) {
            return "数据库中没有任何数据表。";
        }

        if ($pattern !== null && $pattern !== '') {
            $tables = array_values(array_filter(
                $tables,
                static fn($table): bool => stripos((string)$table, $pattern) !== false
            ));

            if (empty($tables)) {
                return "没有匹配 \"{$pattern}\" 的数据表。\n\n提示：pattern 为表名子串（不区分大小写）。";
            }

            return sprintf("匹配 \"%s\" 的表（%d 张）：\n", $pattern, count($tables))
                . implode("\n", $tables);
        }

        return sprintf("数据库共有 %d 张表：\n", count($tables))
            . implode("\n", $tables)
            . "\n\n提示：传 table 查看单表详细结构；传 pattern 按子串过滤表名。";
    }

    /**
     * 描述单个表的结构
     */
    private function describeTable(\PDO $pdo, string $table): string
    {
        // 验证表名（防止 SQL 注入）
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            return "表名格式不合法：{$table}";
        }

        // 检查表是否存在
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if ($stmt->rowCount() === 0) {
            return "表 `{$table}` 不存在。";
        }

        // 获取字段信息
        $stmt    = $pdo->query("DESCRIBE `{$table}`");
        $columns = $stmt->fetchAll();

        // 获取字段注释（通过 INFORMATION_SCHEMA）
        $comments = $this->getColumnComments($pdo, $table);

        // 紧凑格式：每字段一行、双空格分隔，不做定宽对齐
        $output = "表 {$table}（" . count($columns) . " 个字段）\n";

        foreach ($columns as $column) {
            $parts = [
                $column['Field'],
                $column['Type'],
                $column['Null'] === 'YES' ? 'NULL' : 'NOT NULL',
            ];

            $key = $column['Key'] ?? '';
            if ($key !== '') {
                $parts[] = match ($key) {
                    'PRI' => 'PRIMARY',
                    'UNI' => 'UNIQUE',
                    'MUL' => 'INDEX',
                    default => $key,
                };
            }

            $extra = $column['Extra'] ?? '';
            if ($extra !== '') {
                $parts[] = $extra;
            }

            $default = $column['Default'] ?? null;
            if ($default !== null) {
                $parts[] = "默认:" . ($default === '' ? "''" : (string)$default);
            }

            $comment = $comments[$column['Field']] ?? '';
            if ($comment !== '') {
                $parts[] = $comment;
            }

            $output .= implode('  ', $parts) . "\n";
        }

        // 索引信息：单行紧凑展示，如 PRIMARY(id); idx_name(UNIQUE: name)
        try {
            $indexes = $pdo->query("SHOW INDEX FROM `{$table}`")->fetchAll();

            $indexGroups = [];
            foreach ($indexes as $index) {
                $name = $index['Key_name'];
                $indexGroups[$name]['columns'][] = $index['Column_name'];
                $indexGroups[$name]['unique']    = ((int)($index['Non_unique'] ?? 1)) === 0;
            }

            $indexParts = [];
            foreach ($indexGroups as $name => $info) {
                $type         = $name === 'PRIMARY' ? '' : ($info['unique'] ? 'UNIQUE: ' : 'INDEX: ');
                $indexParts[] = $name . '(' . $type . implode(',', $info['columns']) . ')';
            }

            if (!empty($indexParts)) {
                $output .= '索引: ' . implode('; ', $indexParts) . "\n";
            }
        } catch (\Throwable $e) {
            // 忽略索引获取失败
        }

        return $output;
    }

    /**
     * 获取字段注释
     */
    private function getColumnComments(\PDO $pdo, string $table): array
    {
        $comments = [];

        try {
            // 从 INFORMATION_SCHEMA 获取数据库名
            $stmt   = $pdo->query('SELECT DATABASE()');
            $dbName = $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COLUMN_NAME, COLUMN_COMMENT
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $stmt->execute([$dbName, $table]);

            while ($row = $stmt->fetch()) {
                if (!empty($row['COLUMN_COMMENT'])) {
                    $comments[$row['COLUMN_NAME']] = $row['COLUMN_COMMENT'];
                }
            }
        } catch (\Throwable $e) {
            // 忽略注释获取失败
        }

        return $comments;
    }
}
