<?php
namespace CryCMS;

use Exception;
use PDO;

class Db
{
    private static $config = [
        'host' => null,
        'user' => null,
        'password' => null,
        'database' => null,
    ];

    private static $dbh;

    private static $debug = false;

    private static $log = [];

    private $queryTable;
    private $queryTableAS;

    private $querySQL;
    private $queryValues = [];

    private $queryFoundRows = false;
    private $querySelect = [];
    private $queryLeftJoin = [];
    private $queryWhere = [];
    private $queryGroup = [];
    private $queryOrder = [];
    private $queryOffset;
    private $queryLimit;

    private $timeStart;
    private $traceFrom;

    private static $withoutQuotes = [
        'OR', 'AND', 'AS', 'ON', 'LIKE',
    ];

    public function __construct($queryTable = null, $as = null)
    {
        $this->queryTable = $queryTable;
        $this->queryTableAS = $as;

        $this->timeStart = self::getMicroTime();

        $this->traceFrom = $this->getInitiatorFromTrace(debug_backtrace());
    }

    public static function init(array $config): PDO
    {
        try {
            self::$dbh = new PDO("mysql:dbname=" . $config['database'] . ";host=" . $config['host'], $config['user'], $config['password']);
            self::$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$dbh->exec("SET NAMES 'UTF8';");
            self::$dbh->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION';");
            return self::$dbh;
        }
        catch (Exception $e) {
            if (self::$debug) {
                self::print($e);
            }
            die("error db connection");
        }
    }

    private static function singleton(): PDO
    {
        if (!is_object(self::$dbh)) {
            self::$dbh = self::init(self::$config);
        }

        return self::$dbh;
    }

    public static function debug(bool $set): void
    {
        self::$debug = $set;
    }

    public static function config(array $config): void
    {
        if (empty($config)) {
            return;
        }

        foreach ($config as $key => $value) {
            if (array_key_exists($key, self::$config) !== false) {
                self::$config[$key] = $value;
            }
        }
    }

    public static function table(string $queryTable, string $as = null): Db
    {
        return new Db($queryTable, $as);
    }

    public function fields(): array
    {
        $query = "
            SELECT
                COLUMN_NAME,
                DATA_TYPE,
                COLUMN_KEY,
                COLUMN_DEFAULT,
                IS_NULLABLE,
                CHARACTER_MAXIMUM_LENGTH
            FROM
                information_schema.COLUMNS
            WHERE
                TABLE_SCHEMA = DATABASE() AND
                TABLE_NAME = :table
            ORDER BY
                ORDINAL_POSITION
        ";

        $sth = self::singleton()->prepare($query);
        $sth->execute([
            'table' => $this->queryTable
        ]);

        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function sql(): Db
    {
        return new Db();
    }

    public function query(string $query, array $values = []): Db
    {
        $this->querySQL = $query;
        $this->queryValues = $values;

        return $this;
    }

    public function exec(): void
    {
        $this->executeSQL();
    }

    public function getOne()
    {
        $sth = $this->executeSQL();
        if ($sth !== null) {
            return $sth->fetch(PDO::FETCH_ASSOC);
        }

        return null;
    }

    public function getAll()
    {
        $sth = $this->executeSQL();
        if ($sth !== null && $sth !== false) {
            return $sth->fetchAll(PDO::FETCH_ASSOC);
        }

        return null;
    }

    private function executeSQL()
    {
        try {
            $this->buildSQL();

            $querySQL = $this->querySQL;
            $queryValues = $this->queryValues;

            [$querySQL, $queryValues] = $this->fixIn($querySQL, $queryValues);

            $sth = self::singleton()->prepare($querySQL);
            $sth->execute($queryValues);

            if (self::$debug) {
                self::$log[] = [
                    'query' => $this->getSQL(),
                    'template' => $this->querySQL,
                    'values' => $this->queryValues,
                    'time' => self::getMicroTime() - $this->timeStart,
                    'from' => $this->traceFrom,
                ];
            }

            return $sth;
        } catch (Exception $e) {
            if (self::$debug) {
                self::print($querySQL ?? null);
                self::print($this->getSQL());
                self::print($e);
            }
        }

        return null;
    }

    public function getSQL(): string
    {
        $this->buildSQL();

        if (count($this->queryValues) === 0) {
            return $this->querySQL;
        }

        $querySQL = $this->querySQL;
        $queryValues = $this->queryValues;

        [$querySQL, $queryValues] = $this->fixIn($querySQL, $queryValues);

        $keys = array_map('strlen', array_keys($queryValues));
        array_multisort($keys, SORT_DESC, $queryValues);

        foreach($queryValues as $fKey => $fValue) {
            $querySQL = str_replace(":".$fKey, "'".$fValue."'", $querySQL);
        }

        return $querySQL;
    }

    public function create(array $fields, string $tableProperties = ''): void
    {
        $inlineFields = [];
        foreach ($fields as $field => $properties) {
            $inlineFields[] = "`" . $field . "` " . $properties;
        }

        $inlineFields = implode(", ", $inlineFields);

        $this->querySQL = "CREATE TABLE `" . $this->queryTable . "` (" . $inlineFields . ") " . $tableProperties;
        $this->executeSQL();
    }

    public function index(array $fields, $indexType): void
    {
        $inlineFields = [];
        foreach ($fields as $field) {
            $inlineFields[] = "`" . $field . "`";
        }

        $inlineFields = implode(", ", $inlineFields);
        $indexName = implode('_', $fields);

        $this->querySQL = "ALTER TABLE `" . $this->queryTable . "` ADD " . $indexType . " " . $indexName . " (" . $inlineFields . ")";
        $this->executeSQL();
    }

    public function isset(): bool
    {
        $this->querySQL = "
            SELECT
                `table_name`
            FROM
                `information_schema`.`tables`
            WHERE
                table_schema = :database AND
                table_name = :table
        ";

        $this->queryValues = [
            'database' => self::$config['database'],
            'table' => $this->queryTable
        ];

        return !empty($this->getOne());
    }

    public function insert(array $fields = []): void
    {
        $inlineSet = $this->queryValues = [];
        if (count($fields) > 0) {
            foreach ($fields as $key => $value) {
                $inlineSet[] = "`" . $key . "` = :" . $key;
                $this->queryValues[$key] = $value;
            }
        }

        $this->querySQL = "INSERT INTO `" . $this->queryTable . "` " . (count($inlineSet) > 0 ? "SET " . implode(", ", $inlineSet) : "() VALUES ()");
        $this->executeSQL();
    }

    public static function lastInsertId(): string
    {
        return self::singleton()->lastInsertId();
    }

    public function update(array $sets, array $wheres, array $values = []): void
    {
        $inlineSet = [];

        $this->queryValues = $values;

        foreach ($sets as $key => $value) {
            if ($value === 'NULL') {
                $value = null;
            }

            $inlineSet[] = "`" . $key . "` = :upd_" . $key;
            $this->queryValues['upd_' . $key] = $value;
        }

        $inlineWhere = $this->buildWhereForUD($wheres);

        $inlineSet = implode(', ', $inlineSet);
        $inlineWhere = implode(' AND ', $inlineWhere);

        $this->querySQL = "UPDATE `" . $this->queryTable . "` SET " . $inlineSet . " WHERE " . $inlineWhere;
        $this->executeSQL();
    }

    public function delete(array $wheres, array $values = []): void
    {
        $this->queryValues = $values;

        $inlineWhere = $this->buildWhereForUD($wheres);
        $inlineWhere = implode(' AND ', $inlineWhere);

        $this->querySQL = "DELETE FROM `" . $this->queryTable . "` WHERE " . $inlineWhere;
        $this->executeSQL();
    }

    private function buildWhereForUD(array $wheres): array
    {
        $inlineWhere = [];

        foreach ($wheres as $key => $value) {
            if (is_numeric($key)) {
                $inlineWhere[] = self::setQuotes($value);
            }
            else if ($value === null) {
                $inlineWhere[] = '`' . $key . '` IS NULL';
            }
            else {
                $inlineWhere[] = '`' . $key . '` = :whr_' . $key;
                $this->queryValues['whr_' . $key] = $value;
            }
        }

        return $inlineWhere;
    }

    public function select(array $fields = []): Db
    {
        if (count($fields) > 0) {
            foreach ($fields as $field) {
                $this->querySelect[$field] = self::setQuotes($field);
            }
        }

        return $this;
    }

    public function where(array $wheres = []): Db
    {
        if (count($wheres) > 0) {
            foreach ($wheres as $where) {
                $this->queryWhere[] = self::setQuotes($where);
            }
        }

        return $this;
    }

    public function values(array $values = []): Db
    {
        if (count($values) > 0) {
            foreach ($values as $field => $value) {
                $this->queryValues[$field] = $value;
            }
        }

        return $this;
    }

    public function calcRows(bool $set = true): Db
    {
        $this->queryFoundRows = $set;

        return $this;
    }

    public function leftJoin(string $table, string $as, string $on): Db
    {
        $this->queryLeftJoin[$table] = self::setQuotes($table . ' AS ' . $as . ' ON (' . $on . ')');

        return $this;
    }

    public function groupBy(array $groups = []): Db
    {
        if (count($groups) > 0) {
            foreach ($groups as $group) {
                $this->queryGroup[] = self::setQuotes($group);
            }
        }

        return $this;
    }

    public function orderBy(array $orders = []): Db
    {
        if (count($orders) > 0) {
            foreach ($orders as $field => $order) {
                $this->queryOrder[$field] = self::setQuotes($field) . ' ' . $order;
            }
        }

        return $this;
    }

    public function offset(int $offset): Db
    {
        $this->queryOffset = $offset;

        return $this;
    }

    public function limit(int $limit): Db
    {
        $this->queryLimit = $limit;

        return $this;
    }

    protected function fixIn(string $query, array $fields = []): array
    {
        preg_match_all('/IN \((.*)\)/iU', $query, $matches);
        if (!empty($matches[1]) && is_array($matches[1]) && count($matches) > 0) {
            foreach ($matches[1] as $inKey) {
                $key = str_replace(":", "", $inKey);
                if (isset($fields[$key])) {
                    $num_of_in = count($fields[$key]);

                    $keys = [];
                    $values = [];

                    if ($num_of_in > 0) {
                        foreach ($fields[$key] as $n => $value) {
                            $n = sprintf("%03d", $n);
                            $keys[] = ":" . $key . $n;
                            $values[$key . $n] = $value;
                        }
                    }

                    $query = str_replace(":" . $key, implode(", ", $keys), $query);

                    unset($fields[$key]);
                    $fields += $values;
                }
            }
        }

        return [$query, $fields];
    }

    public static function setQuotes(string $field): string
    {
        $new = str_replace("`", "", $field);

        return preg_replace_callback(
            '/([:a-zA-Z0-9_]+)/i',
            static function ($matches) {
                if (strpos($matches[0], ":") !== false) {
                    return $matches[0];
                }

                $match = mb_strtoupper($matches[0], 'UTF-8');
                if (in_array($match, self::$withoutQuotes, true) === true) {
                    return $matches[0];
                }

                return "`" . $matches[0] . "`";
            },
            $new
        );
    }

    public function buildSQL(): void
    {
        if (!empty($this->querySQL)) {
            return;
        }

        $sql = [];

        $sql[] = $this->getSQLSelect();
        $sql[] = $this->getSQLFrom();
        $sql[] = $this->getSQLLeftJoin();
        $sql[] = $this->getSQLWhere();
        $sql[] = $this->getSQLGroup();
        $sql[] = $this->getSQLOrder();
        $sql[] = $this->getSQLOffset();
        $sql[] = $this->getSQLLimit();

        $this->querySQL = implode("", $sql);
    }

    private function getSQLSelect(): string
    {
        return 'SELECT ' .
            ($this->queryFoundRows ? 'SQL_CALC_FOUND_ROWS ' : '') .
            (!empty($this->querySelect) ? implode(', ', $this->querySelect) : '*') .
            ' ';
    }

    private function getSQLFrom(): string
    {
        return 'FROM `' . $this->queryTable . '` ' . (!empty($this->queryTableAS) ? 'AS `' . $this->queryTableAS . '` ' : '');
    }

    private function getSQLLeftJoin(): string
    {
        $joins = [];

        foreach ($this->queryLeftJoin as $join) {
            $joins[] = 'LEFT JOIN ' . $join . ' ';
        }

        return implode('', $joins);
    }

    private function getSQLWhere(): string
    {
        if (empty($this->queryWhere)) {
            return '';
        }

        return 'WHERE ' . implode(' AND ', $this->queryWhere) . ' ';
    }

    private function getSQLGroup(): string
    {
        if (empty($this->queryGroup)) {
            return '';
        }

        return 'GROUP BY ' . implode(', ', $this->queryGroup) . ' ';
    }

    private function getSQLOrder(): string
    {
        if (empty($this->queryOrder)) {
            return '';
        }

        return 'ORDER BY ' . implode(', ', $this->queryOrder) . ' ';
    }

    private function getSQLOffset(): string
    {
        if (empty($this->queryOffset)) {
            return '';
        }

        return 'OFFSET ' . $this->queryOffset . ' ';
    }

    private function getSQLLimit(): string
    {
        if (empty($this->queryLimit)) {
            return '';
        }

        return 'LIMIT ' . $this->queryLimit . ' ';
    }

    public static function getFoundRows(): int
    {
        $result = self::sql()->query('SELECT FOUND_ROWS()')->getOne();
        return $result['FOUND_ROWS()'] ?? 0;
    }

    public function truncate(): void
    {
        $this->querySQL = 'TRUNCATE `' . $this->queryTable . '`';
        $this->executeSQL();
    }

    public function drop(): void
    {
        $this->querySQL = 'DROP TABLE `' . $this->queryTable . '`';
        $this->executeSQL();
    }

    public static function getLog(): array
    {
        return self::$log;
    }

    public static function getMicroTime(): float
    {
        [$uSec, $sec] = explode(" ", microtime());
        return ((float)$uSec + (float)$sec);
    }

    private function getInitiatorFromTrace($trace): string
    {
        if (!empty($trace)) {
            foreach ($trace as $step) {
                if (empty($step['file'])) {
                    continue;
                }

                if ($step['file'] === __FILE__) {
                    continue;
                }

                return $step['file'] . ':' . $step['line'];
            }
        }

        return '';
    }

    public static function print($data): void
    {
        $end = "<br>";
        if (strpos(PHP_SAPI, 'cli') !== false) {
            $end = "\r\n";
        }

        if (is_string($data) || is_int($data) || is_float($data)) {
            echo $data . $end;
            return;
        }

        if (is_bool($data)) {
            var_dump($data);
            return;
        }

        print_r($data);
    }
}