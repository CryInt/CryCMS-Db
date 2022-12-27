# CryCMS-Db

Класс для работы с MySQL через PDO.
Позволяет как писать запросы руками, так и через цепочку методов.

Представляет из себя singleton с методами обертками.

Имеет встроенный лог и фиксацией времени выполнения.

`Настройка`
```php
Db::config([
    'host' => 'localhost',
    'user' => 'test',
    'password' => 'test',
    'database' => 'test',
]);
```

`Включение Debug режима`
```php
Db::debug(true);
```
---

### Есть два типа синтаксиса

`Через прямой SQL запрос`
```php
Db::sql()->query('query', [])->exec();
Db::sql()->query('query', [])->getOne();
Db::sql()->query('query', [])->getAll();
```

`Через билдер`
```php
$result = Db::table('table')->getOne();
$result = Db::table('table')->getAll();
```
---

### Примеры

`Создание таблиц`
```php
Db::table('table')->create([
    'id' => 'INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'name' => 'VARCHAR(255)',
    'date' => 'DATETIME',
], 'ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COMMENT="TEST"');
```

`Добавление индекса`
```php
Db::table('table')->index(['id', 'date'], 'UNIQUE');
```

`Очистка таблицы`
```php
Db::table('table')->truncate();
```

`Удаление таблицы`
```php
Db::table('table')->drop();
```
---

`Получение списка столбцов в таблице`
```php
Db::table('table')->fields();
```

`Лог запросов (только debug=true)`
```php
$log = Db::getLog();
```

`Получить результирующий запрос`
```text
getOne() or getAll() change to getSQL()
```
---

`Добавить запись в таблицу`
```php
Db::table('table')->insert([
    'name' => 'first',
    'date' => date('Y-m-d'),
]);
```

`Получить autoincrement добавленной записи`
```php
$id = Db::lastInsertId();
```

`Получение одной записи`
```php
$one = Db::table('table')
    ->select(['id', 'name', 'date'])
    ->where(['id = :id'])
    ->values(['id' => $id])
    ->getOne();
```

`Получение нескольких записей`
```php
$all = Db::table('table', 't')
    ->select(['t.id', 'td.field_1', 'td.field_2'])
    ->calcRows()
    ->leftJoin('testData', 'td', 'td.test_id = t.id')
    ->where(['t.date <= :date'])
    ->values(['date' => date('Y-m-d')])
    ->offset(0)
    ->limit(5)
    ->groupBy(['t.id'])
    ->orderBy(['t.id' => 'DESC'])
    ->getAll();
```

`Получение количества записей (только с calcRows)`
```php
$count = Db::getFoundRows();
```

`Изменение - вариант 1`
```php
Db::table('table')->update([
    'name' => 'NAME2'
], [
    'id' => $id
]);
```

`Изменение - вариант 2`
```php
Db::table('table')->update([
    'name' => 'NAME3',
], [
    'id = :id', // array of SQL with placeholders
], [
    'id' => $id,
]);
```

`Удаление - вариант 1`
```php
Db::table('table')->delete([
    'id' => $id
]);
```

`Удаление - вариант 2`
```php
Db::table('test')->delete([
    'date <= :date', // array of SQL with placeholders
], [
    'date' => date('Y-m-d')
]);
```

`Новый инстанс с отдельным подключением`
```php
use CryCMS\Db;

class Db2 extends Db
{
    protected static $config;
    protected static $dbh;
    protected static $debug = false;
    protected static $log = [];
}

Db2::config(
    [
        'host' => '',
        'user' => '',
        'password' => '',
        'database' => '',
    ]
);
```

