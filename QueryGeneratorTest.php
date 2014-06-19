<?php

require("QueryGenerator.php");

/**
 * Designed to work with PHPUnit
 */
class QueryGeneratorTest extends PHPUnit_Framework_TestCase {
   public function testEmptyQuery() {
      $missingSelect = function() {
         $qGen = new QueryGenerator();
         $qGen->from(['table']);
         $qGen->build();
      };
      $missingFrom = function() {
         $qGen = new QueryGenerator();
         $qGen->select(['field']);
         $qGen->build();
      };
      $missingSelectAndFrom = function() {
         $qGen = new QueryGenerator();
         $qGen->build();
      };
      $this->assertTrue((bool)$this->didThrowException($missingSelect));
      $this->assertTrue((bool)$this->didThrowException($missingFrom));
      $this->assertTrue((bool)$this->didThrowException($missingSelectAndFrom));
   }

   public function testSmallQuery() {
      $callback = function() {
         $qGen = new QueryGenerator();
         $qGen->select(['field']);
         $qGen->from(['table']);

         return $qGen->build();
      };
      $error = $this->didThrowException($callback);
      $this->assertFalse($error, $error);

      $expectedQuery = <<<EOT
SELECT field
FROM table
EOT;
      $expectedParams = [];
      list($actualQuery, $actualParams) = $callback();
      $this->assertEquals($actualQuery, $expectedQuery);
      $this->assertEquals($actualParams, $expectedParams);
   }

   public function testBigQuery() {
      $callback = function() {
         $qGen = new QueryGenerator();
         $qGen->select(['field1', 'field2']);
         $qGen->from(['table1', 'table2']);
         $qGen->join([
            'INNER JOIN table3 USING (asdf)',
            'OUTER JOIN table4 ON foo = bar'
         ]);
         $qGen->where(['where1', 'where2 OR where3']);
         $qGen->group(['group1', 'group2']);
         $qGen->having(['having1 > ?', 'having2 < ?'], [0, 0]);
         $qGen->order(['order1', 'order2']);
         $qGen->limit([3]);
         $qGen->offset(['?'], [5]);

         return $qGen->build();
      };
      $error = $this->didThrowException($callback);
      $this->assertFalse($error, $error);

      $expectedQuery = <<<EOT
SELECT field1, field2
FROM table1, table2
INNER JOIN table3 USING (asdf)
OUTER JOIN table4 ON foo = bar
WHERE (where1) AND (where2 OR where3)
GROUP BY group1, group2
HAVING (having1 > ?) AND (having2 < ?)
ORDER BY order1, order2
LIMIT 3
OFFSET ?
EOT;
      $expectedParams = [0, 0, 5];
      list($actualQuery, $actualParams) = $callback();
      $this->assertSame($actualQuery, $expectedQuery);
      $this->assertSame($actualParams, $expectedParams);
   }

   public function testSingleArguments() {
      $callback = function() {
         $qGen = new QueryGenerator();
         $qGen->select('field');
         $qGen->from('table');

         return $qGen->build();
      };
      $error = $this->didThrowException($callback);
      $this->assertFalse($error, $error);

      $expectedQuery = <<<EOT
SELECT field
FROM table
EOT;
      $expectedParams = [];
      list($actualQuery, $actualParams) = $callback();
      $this->assertEquals($actualQuery, $expectedQuery);
      $this->assertEquals($actualParams, $expectedParams);
   }

   public function testUpdateQuery() {
      $qGen = (new QueryGenerator())
         ->update('table')
         ->set('field', 'value');
      list($actualQuery, $actualParams) = $qGen->build();

      $expectedQuery = <<<EOT
UPDATE table
SET field = ?
EOT;
      $expectedParams = ['value'];
      $this->assertEquals($actualQuery, $expectedQuery);
      $this->assertEquals($actualParams, $expectedParams);

      $qGen->set('field = other_column');
      list($actualQuery, $actualParams) = $qGen->build();
      $expectedQuery = <<<EOT
UPDATE table
SET field = ?,
field = other_column
EOT;
      $this->assertEquals($actualQuery, $expectedQuery);
      $this->assertEquals($actualParams, $expectedParams);
   }

   public function testInsertQuery() {
      $qGen = (new QueryGenerator())
         ->insert('table')
         ->set('field', 'value');
      list($actualQuery, $actualParams) = $qGen->build();

      $expectedQuery = <<<EOT
INSERT table
SET field = ?
EOT;
      $expectedParams = ['value'];
      $this->assertEquals($actualQuery, $expectedQuery);
      $this->assertEquals($actualParams, $expectedParams);

      $qGen->set('field = other_column');
      list($actualQuery, $actualParams) = $qGen->build();
      $expectedQuery = <<<EOT
INSERT table
SET field = ?,
field = other_column
EOT;
      $this->assertEquals($actualQuery, $expectedQuery);
      $this->assertEquals($actualParams, $expectedParams);
   }

   public function didThrowException($callback) {
      try {
         $callback();
         return false;
      } catch (Exception $e) {
         return $e->getMessage() ?: true;
      }
   }
}

