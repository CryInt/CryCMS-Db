<?php
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

    private $querySQL;
    private $queryValues = [];

    public function __construct($queryTable = null)
    {
        $this->queryTable = $queryTable;
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
                                                            self::print("INIT");
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

    public static function table(string $queryTable): Db
    {
        return new Db($queryTable);
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

    public function getOne(): ?array
    {
        $sth = $this->executeSQL();
        if ($sth !== null && $sth !== false) {
            return $sth->fetch(PDO::FETCH_ASSOC);
        }

        return null;
    }

    public function getAll(): ?array
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
            $querySQL = $this->querySQL;
            $queryValues = $this->queryValues;

            [$querySQL, $queryValues] = $this->fixIn($querySQL, $queryValues);

            $sth = self::singleton()->prepare($querySQL);
            $sth->execute($queryValues);
            return $sth;
        } catch (Exception $e) {
            if (self::$debug) {
                self::print($querySQL);
                self::print($this->getSQL());
                self::print($e);
            }
        }

        return null;
    }

    public function getSQL(): string
    {
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
        $this->exec();
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
        $this->exec();
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
        $this->exec();
    }

    public static function lastInsertId(): string
    {
        return self::singleton()->lastInsertId();
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

    public static function print($data): void
    {
        $end = "<br>";
        if (is_string($data) && strpos(PHP_SAPI, 'cli') !== false) {
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