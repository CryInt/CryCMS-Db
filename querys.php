<?php
require_once 'Db.php';

Db::config([
    'host' => '91.226.80.24',
    'user' => 'test',
    'password' => 'test',
    'database' => 'test',
]);

Db::debug(true);

$tableFields = Db::table('w')->fields();

if (empty($tableFields)) {
    Db::sql()->query("
        CREATE TABLE `w` (
            `q1` int(12) UNSIGNED NOT NULL,
            `q2` varchar(50) DEFAULT NULL,
            `q3` datetime NOT NULL,
            `q4` decimal(10,2) NOT NULL DEFAULT 0.00
        )
        ENGINE=MyISAM
        DEFAULT
        CHARSET=utf8mb4
        COMMENT='TEST';
    ")->exec();

    Db::sql()->query("INSERT INTO `w` SET `q3` = :date", ['date' => date('Y-m-d')])->exec();
    Db::sql()->query("INSERT INTO `w` SET `q3` = :date", ['date' => date('Y-m-d')])->exec();

    $result = Db::sql()->query("SELECT * FROM `w`")->getOne();
    Db::print($result);

    $result = Db::sql()->query("SELECT * FROM `w` WHERE `q3` IN (:date)", [
        'date' => [
            date('Y-m-d'),
            date('Y-m-d', strtotime('-1 month'))
        ]
    ])->getAll();
    Db::print($result);

    Db::sql()->query("DROP TABLE `w`")->exec();
}

$tableFields = Db::table('test')->fields();

if (empty($tableFields)) {
    Db::table('test')->create([
        'id' => 'INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'name' => 'VARCHAR(255)',
        'date' => 'DATETIME',
    ], 'ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COMMENT="TEST"');

    Db::table('test')->index(['id', 'date'], 'UNIQUE');
}

$isset = Db::table('testData')->isset();
Db::print($isset);

if ($isset === false) {
    Db::table('testData')->create([
        'test_id' => 'INT(10) UNSIGNED',
        'field_1' => 'VARCHAR(50)',
        'field_2' => 'VARCHAR(50)',
    ]);
}

Db::table('test')->insert([
    'name' => 'first',
    'date' => date('Y-m-d'),
]);

$id = Db::lastInsertId();

Db::print('ID: ' . $id);

$one = Db::table('test')
    ->select(['id', 'name', 'date'])
    ->where(['id = :id'])
    ->values(['id' => $id])
    //->getSQL();
    ->getOne();

Db::print($one);

Db::table('testData')->insert([
    'test_id' => $id,
    'field_1' => 'F1',
    'field_2' => 'F2',
]);

$all = Db::table('test', 't')
    ->select(['t.id', 'td.field_1', 'td.field_2'])
    ->calcRows()
    ->leftJoin('testData', 'td', 'td.test_id = t.id')
    ->where(['t.date <= :date'])
    ->values(['date' => date('Y-m-d')])
    ->offset(0)
    ->limit(5)
    ->groupBy(['t.id'])
    ->orderBy(['t.id' => 'DESC'])
    //->getSQL();
    ->getAll();

Db::print("ALL:");
Db::print($all);

$count = Db::getFoundRows();

Db::print('COUNT: ' . $count);

Db::table('testData')->update([
    'field_1' => 'F5'
], [
    'test_id' => $id
]);

Db::table('testData')->update([
    'field_2' => 'F6',
], [
    'test_id = :id',
], [
    'id' => $id,
]);

Db::table('test')->update(['date' => '1984-04-20'], ['date' => null]);

Db::table('testData')->delete([
    'test_id' => $id
]);

Db::table('test')->delete([
    'date <= :date'
], [
    'date' => date('Y-m-d')
]);

Db::table('testData')->truncate();
Db::table('testData')->drop();

Db::table('test')->drop();

$log = Db::getLog();
Db::print($log);