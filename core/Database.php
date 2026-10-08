<?php
/**
 * 数据库访问层（PDO 单例 + 预处理，防 SQL 注入）
 */
declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;
use PDOStatement;

final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? '3306',
            $config['name'] ?? '',
            $config['charset'] ?? 'utf8mb4'
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES  => false, // 真正预处理
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ];
        try {
            $this->pdo = new PDO($dsn, $config['user'] ?? '', $config['pass'] ?? '', $options);
        } catch (PDOException $e) {
            throw new \RuntimeException('数据库连接失败：' . $e->getMessage(), 0, $e);
        }
    }

    public static function instance(array $config = []): self
    {
        if (self::$instance === null) {
            if (!$config) {
                $config = $GLOBALS['_db_config'] ?? [];
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** 执行查询，返回 PDOStatement（参数绑定） */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /** 插入数据，返回 lastInsertId */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->ident($table),
            implode(', ', array_map([$this, 'ident'], $cols)),
            implode(', ', $placeholders)
        );
        $this->query($sql, $data);
        return (int)$this->pdo->lastInsertId();
    }

    /** 更新数据，$where 为条件字符串，$whereParams 为绑定参数。返回受影响行数 */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = [];
        foreach (array_keys($data) as $col) {
            $set[] = $this->ident($col) . ' = :' . $col;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->ident($table), implode(', ', $set), $where);
        $stmt = $this->query($sql, array_merge($data, $whereParams));
        return $stmt->rowCount();
    }

    /** 删除，返回受影响行数 */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', $this->ident($table), $where);
        return $this->query($sql, $params)->rowCount();
    }

    /** 统计行数 */
    public function count(string $table, string $where = '', array $params = []): int
    {
        $sql = sprintf('SELECT COUNT(*) FROM %s', $this->ident($table));
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        return (int)$this->fetchColumn($sql, $params);
    }

    /** 安全表名/字段名（仅允许字母数字下划线） */
    private function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('非法标识符: ' . $name);
        }
        return '`' . $name . '`';
    }

    /** 事务封装 */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
