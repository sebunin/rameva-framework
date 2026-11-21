<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Core
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Core;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс Db предоставляет безопасную обёртку для работы с базой данных через PDO.
 */
class Db
{
    /** @var \PDO Экземпляр подключения к базе данных */
    public \PDO $pdo;

    /**
     * Конструктор инициализирует подключение к базе данных на основе конфигурации.
     *
     * @param array|null $config Параметры подключения. Если null, загружаются из app/Config/Database.php
     * @throws \RuntimeException В случае ошибки подключения
     */
    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $configFile = APPPATH . '/Config/Database.php';
            $config = file_exists($configFile) ? (require $configFile) : [];
        }

        $driver   = $config['driver'] ?? 'mysql';
        $host     = $config['host'] ?? 'localhost';
        $port     = $config['port'] ?? 3306;
        $database = $config['database'] ?? '';
        $charset  = $config['charset'] ?? 'utf8mb4';
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';

        $dsn = "{$driver}:host={$host};port={$port};dbname={$database};charset={$charset}";

        try {
            $this->pdo = new \PDO(
                $dsn,
                $username,
                $password,
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (\PDOException $e) {
            throw new \RuntimeException('Database connection error: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Выполняет SQL-запрос с параметрами.
     *
     * @param string $query Текст SQL-запроса
     * @param array|null $params Параметры для подготовленного выражения
     * @return \PDOStatement
     */
    public function execute(string $query, ?array $params = null): \PDOStatement
    {
        try {
            if ($params === null) {
                return $this->pdo->query($query);
            }

            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            throw new \RuntimeException('Database query error: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Возвращает все строки результата выполнения запроса.
     *
     * @param string $query SQL-запрос
     * @param array|null $params Параметры
     * @return array
     */
    public function query(string $query, ?array $params = null): array
    {
        return $this->execute($query, $params)->fetchAll();
    }

    /**
     * Возвращает одну строку результата.
     *
     * @param string $query SQL-запрос
     * @param array|null $params Параметры
     * @return array|null
     */
    public function queryRow(string $query, ?array $params = null): ?array
    {
        $result = $this->execute($query, $params)->fetch();
        return $result === false ? null : $result;
    }

    /**
     * Возвращает одно скалярное значение.
     *
     * @param string $query SQL-запрос
     * @param array|null $params Параметры
     * @return mixed
     */
    public function queryValue(string $query, ?array $params = null): mixed
    {
        return $this->execute($query, $params)->fetchColumn();
    }

    /**
     * Возвращает ID последней вставленной записи.
     *
     * @param string|null $name Имя последовательности (для PostgreSQL)
     * @return string
     */
    public function lastInsertId(?string $name = null): string
    {
        return $this->pdo->lastInsertId($name);
    }
}
