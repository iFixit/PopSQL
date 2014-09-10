# PopSQL

[![Build Status](https://secure.travis-ci.org/iFixit/PopSQL.png?branch=master)](http://travis-ci.org/iFixit/PopSQL)

## What is it?

PopSQL (pronounced "popsicle") provides a simple, objective approach to
conditionally constructing MySQL statements.

## Why do I want it?

Building conditional queries by hand is tedious, error prone, and ugly:

```php
$fields = ['field1', 'field2'];
if ($someCondition)
   $fields[] = 'field3';
else
   $fields[] = 'field4';
$fieldClause = implode(',', $fields);

$whereConditions = [];
$whereParameters = [];
if ($someCondition) {
   $whereConditions[] = 'field1 > ? AND field3 < ?';
   $whereParameters[] = 0;
   $whereParameters[] = 1337;
} else if ($someOtherCondition) {
   $whereConditions[] = 'field4 != "" OR field2 < ?';
   $whereParameters[] = 0;
}

if ($whereConditions)
   $whereClause = 'WHERE (' . implode('', ) . ')';
else
   $whereClause = '';

$query = <<<EOT
SELECT $fieldClause
FROM my_table mt
JOIN my_other_table mot ON mt.field1 > mot.value + ?
$whereClause
EOT;

$params = array_merge([7], $whereParameters);
```

## How do I use it?

Working with an object is much prettier, and much harder to mess up:

```php
$qGen = new QueryGenerator();

$qGen->select(['field1', 'field2']);
$qGen->select($someCondition ? 'field3' : 'field4');

$qGen->from('my_table mt')->
 join('JOIN my_other_table mot ON mt.field1 > mot.value + ?', 7);

if ($someCondition) {
   $qGen->where('field1 > ? AND field3 < ?', [0, 1337]);
} else if ($someOtherCondition) {
   $qGen->where('field4 != "" OR field2 < ?', 0);
}

list($query, $params) = $qGen->build();

```

Assuming `$someCondition` is true and `$someOtherCondition` is false, the
above example produces the following query:

```sql
SELECT field1, field2, field3
FROM my_table mt
JOIN my_other_table mot ON mt.field1 > mot.value + ?
WHERE field1 > ? AND field3 < ?
```

... and the following parameters:

```php
[7, 0, 1337]
```

QueryGenerator has support for SELECT, INSERT, REPLACE, UPDATE, and DELETE
queries. Each of those query types supports a selection of different clauses:
 * select: from, join, where, group, having, order, limit, offset
 * insert: set, columns, values, duplicate
 * replace: set, columns, values
 * update: set, where, order, limit
 * delete: from, where, order, limit

Simply call the member function of the clause you want to add to, passing
strings of SQL and (optionally) parameters for use in prepared statements.
