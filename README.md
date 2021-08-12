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
`Получение списка столбцов в таблице`
```php
Db::table('table')->fields();
```
---

###Есть два типа синтаксиса

`Через прямой SQL запрос`
```php
Db::sql()->query('query', [])->exec();
Db::sql()->query('query', [])->getOne();
Db::sql()->query('query', [])->getAll();
```

---

`Создание таблиц`
```php
Db::table('table')->create([
    'id' => 'INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'name' => 'VARCHAR(255)',
    'date' => 'DATETIME',
], 'ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COMMENT="TEST"');
```
