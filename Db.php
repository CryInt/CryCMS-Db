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
    private $queryValues;

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
        self::singleton()->prepare($this->querySQL)->execute($this->queryValues);
    }

    public function getOne(): ?array
    {
        $sth = $this->executeSQL();
        if ($sth !== null) {
            return $sth->fetch(PDO::FETCH_ASSOC);
        }

        return null;
    }

    public function getAll(): ?array
    {
        $sth = $this->executeSQL();
        if ($sth !== null) {
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

    public function create(array $fields, string $properties = ''): void
    {
        print_r($this->queryTable);
        print_r($fields);
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

        print_r($data);
    }
}