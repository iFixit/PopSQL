PHPQueryGenerator
------
[![Build Status](https://secure.travis-ci.org/iFixit/PHPQueryGenerator.png?branch=master)](http://travis-ci.org/iFixit/PHPQueryGenerator)

PHPQueryGenerator provides a simple approach to conditionally constructing
MySQL SELECT statements.

Example usage:

```php
$qGen = new QueryGenerator();
$qGen->select(['field1', 'field2']);
$qGen->from(['table1']);
$qGen->join(['INNER JOIN table2 ON asdf = ?'], 'asdf');
$qGen->where(['condition1 < ?', 'condition2 > ?'], [5, 7]);
list($query, $params) = $qGen->build();
```

Generated query:

```
SELECT field1, field2
FROM table1
INNER JOIN table2 ON asdf = ?
WHERE (condition1 < ?) AND (condition2 > ?)
```

Generated parameters:

```php
['asdf', 5, 7]
```

