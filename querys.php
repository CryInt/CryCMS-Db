<?php
require_once 'Db.php';

Db::config([
    'host' => '91.226.80.24',
    'user' => 'test',
    'password' => 'test',
    'database' => 'test',
]);

Db::debug(true);

$tableFields = Db::table('test')->fields();

if (empty($tableFields)) {
    Db::table('test')->create([
        'id' => 'INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'name' => 'VARCHAR(255)',
        'date' => 'DATETIME',
    ]);

    Db::table('test')->index(['id', 'date'], 'BTREE');
}

$isset = Db::table('testData')->isset();

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

print_r($id);

$one = Db::table('test')
    ->select(['name', 'date'])
    ->where(['id = :id'])
    ->values(['id' => $id])
    ->getOne();

print_r($one);

Db::table('testData')->insert([
    'test_id' => $id,
    'field_1' => 'F1',
    'field_2' => 'F2',
]);

$all = Db::table('test', 't')
    ->select(['t.id', 'td.f1', 'td.f2'])
    ->leftJoin('testData', 'td', 'td.test_id = t.id')
    ->where(['t.date =< :date'])
    ->values(['date' => date('Y-m-d')])
    ->offset(0)
    ->limit(10)
    ->orderBy(['t.id' => 'DESC'])
    ->getAll();

print_r($all);

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

Db::table('testData')->delete([
    'id' => $id
]);

Db::table('test')->delete([
    'date =< :date'
], [
    'date' => date('Y-m-d')
]);

Db::table('testData')->truncate();
Db::table('testData')->drop();

Db::table('test')->drop();

$log = Db::getLog();
print_r($log);