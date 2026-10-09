<?php

declare(strict_types=1);

namespace ymwl\think8mcp\MCP\Tools;

use think\App;

/**
 * 数据库备份工具 - 按项目数据库配置导出单表（结构 + 数据）到 runtime/backup/
 *
 * 用于落实"高危操作前先备份"的规范：生成的文件可直接导入恢复
 *（含 DROP TABLE IF EXISTS + CREATE TABLE + 批量 INSERT）。
 */
class DbBackupTool implements ToolInterface
{
    /** 每批 INSERT 的行数 */
    private const BATCH_SIZE = 200;

    public function __construct(
        private App $app
    ) {}

    public function getName(): string
    {
        return 'db_backup';
    }

    public function getDescription(): string
    {
        return '按项目数据库配置导出单张表（建表语句 + 数据）到 runtime/backup/，高危操作前的标准备份动作。支持仅结构、限制行数。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'table' => [
                    'type'        => 'string',
                    'description' => '要备份的表名（不含前缀，如 admin；也支持完整表名 ymwl_admin）',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => '最多导出的数据行数（0 或不填 = 全部导出）',
                    'minimum'     => 0,
                ],
                'structure_only' => [
                    'type'        => 'boolean',
                    'description' => '仅导出表结构，不含数据（默认 false）',
                ],
            ],
            'required' => ['table'],
        ];
    }

    public function execute(array $params): string
    {
        $table         = trim((string)($params['table'] ?? ''));
        $limit         = max((int)($params['limit'] ?? 0), 0);
        $structureOnly = !empty($params['structure_only']);

        if ($table === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            return "错误：table 参数不合法（仅允许字母/数字/下划线，需以字母或下划线开头）：{$table}";
        }

        try {
            $pdo = $this->getPdo();
        } catch (\Throwable $e) {
            return "无法连接到数据库：{$e->getMessage()}\n\n请检查 .env 或 config/database.php 中的数据库配置。";
        }

        // 存在性校验（同时防止表名后缀注入）
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if ($stmt->rowCount() === 0) {
            return "表 `{$table}` 不存在。\n\n提示：表名可带前缀（如 ymwl_admin）；可用 get_database_schema 查看表清单。";
        }

        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $createSql = (string)($createRow['Create Table'] ?? $createRow['Create View'] ?? '');

        if ($createSql === '') {
            return "无法获取表 `{$table}` 的建表语句。";
        }

        $backupDir = rtrim($this->app->getRuntimePath(), '\\/') . DIRECTORY_SEPARATOR . 'backup';

        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            return "无法创建备份目录：{$backupDir}";
        }

        $file = $backupDir . DIRECTORY_SEPARATOR . $table . '_' . date('Ymd_His') . '.sql';
        $fp   = fopen($file, 'wb');

        if ($fp === false) {
            return "无法写入备份文件：{$file}";
        }

        $rowCount = 0;

        try {
            $columns   = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_COLUMN);
            $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $rowCount  = $limit > 0 ? min($totalRows, $limit) : $totalRows;

            fwrite($fp, "-- think8-mcp db_backup\n");
            fwrite($fp, "-- 表: {$table}\n");
            fwrite($fp, '-- 时间: ' . date('Y-m-d H:i:s') . "\n");
            fwrite($fp, '-- 行数: ' . $rowCount
                . ($limit > 0 ? "（共 {$totalRows} 行，限前 {$limit} 行）" : '')
                . ($structureOnly ? '（仅结构）' : '') . "\n");
            fwrite($fp, "SET NAMES utf8mb4;\n");
            fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($fp, $createSql . ";\n\n");

            if (!$structureOnly && $rowCount > 0) {
                $columnList = '`' . implode('`,`', array_map('strval', $columns)) . '`';
                $prefix     = "INSERT INTO `{$table}` ({$columnList}) VALUES\n";

                $offset = 0;
                while ($offset < $rowCount) {
                    $batch = $pdo->query("SELECT * FROM `{$table}` LIMIT " . self::BATCH_SIZE . " OFFSET {$offset}")
                        ->fetchAll(\PDO::FETCH_ASSOC);

                    if ($batch === []) {
                        break;
                    }

                    $values = [];
                    foreach ($batch as $row) {
                        $cells = [];
                        foreach ($columns as $column) {
                            $cells[] = $this->sqlValue($pdo, $row[$column] ?? null);
                        }
                        $values[] = '(' . implode(',', $cells) . ')';
                    }

                    fwrite($fp, $prefix . implode(",\n", $values) . ";\n");
                    $offset += count($batch);
                }
            }
        } finally {
            fclose($fp);
        }

        $size = @filesize($file) ?: 0;

        return sprintf(
            "已备份 `%s`：%d 行%s → runtime/backup/%s（%s）\n\n说明：文件含 DROP TABLE + CREATE TABLE%s，可直接导入恢复；建议高危操作前同时备份关联表。",
            $table,
            $rowCount,
            $limit > 0 && !$structureOnly ? "（共 {$totalRows} 行，限前 {$limit} 行）" : ($structureOnly ? '（仅结构）' : ''),
            basename($file),
            $this->formatSize((int)$size),
            $structureOnly ? '' : ' + INSERT 数据'
        );
    }

    /**
     * PDO 连接的取参逻辑与 SchemaTool 保持一致（config/database.php + .env 兜底）
     */
    private function getPdo(): \PDO
    {
        $config = [];

        try {
            $dbConfig    = (array)$this->app->config->get('database');
            $defaultConn = $dbConfig['default'] ?? 'mysql';
            $connections = $dbConfig['connections'] ?? [];
            $config      = $connections[$defaultConn] ?? (isset($dbConfig['hostname']) ? $dbConfig : []);
        } catch (\Throwable) {
        }

        if ($config === []) {
            $config = $this->getConfigFromEnv();
        }

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

        return new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT            => 5,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function getConfigFromEnv(): array
    {
        $envFile = $this->app->getRootPath() . '.env';
        $config  = [];

        if (!is_file($envFile)) {
            return $config;
        }

        $map = [
            'DB_HOST'     => 'hostname',
            'DB_PORT'     => 'hostport',
            'DB_DATABASE' => 'database',
            'DB_USERNAME' => 'username',
            'DB_PASSWORD' => 'password',
            'DB_CHARSET'  => 'charset',
        ];

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key           = trim($key);

            if (isset($map[$key])) {
                $config[$map[$key]] = trim($value, " \t\n\r\0\x0B\"'");
            }
        }

        return $config;
    }

    /**
     * SQL 值转义（NULL/布尔/数字直出，其余 quote）
     */
    private function sqlValue(\PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return $pdo->quote((string)$value);
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
