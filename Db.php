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

    private $table;

    public function __construct($table)
    {
        $this->table = $table;
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
            print_r($e);
            die("error db connection");
        }
    }

    private static function singleton(): PDO
    {
        if (!is_object(self::$dbh)) {
            print_r("INIT");
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

    public static function table(string $table): Db
    {
        return new Db($table);
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
            'table' => $this->table
        ]);

        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $fields): void
    {
        print_r($this->table);
        print_r($fields);
    }

    public static function test(): void
    {

    }
}