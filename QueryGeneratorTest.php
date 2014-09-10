<?php

require("QueryGenerator.php");

/**
 * Designed to work with PHPUnit
 */
class QueryGeneratorTest extends PHPUnit_Framework_TestCase {
   public function testIncompleteQuery() {
      $incompleteQueryClauseSets = [
         'select' => [
            [],
            ['select'],
            ['from'],
         ],
         'insert' => [
            [],
            ['insert'],
            ['set'],
            ['insert', 'columns'],
            ['insert', 'values'],
            ['columns', 'values'],
         ],
         'replace' => [
            [],
            ['replace'],
            ['set'],
            ['replace', 'columns'],
            ['replace', 'values'],
            ['columns', 'values'],
         ],
         'update' => [
            [],
            ['update'],
            ['set'],
         ],
         'delete' => [
            [],
            ['delete'],
            ['from'],
         ],
      ];

      foreach ($incompleteQueryClauseSets as $clauseSets) {
         foreach ($clauseSets as $clauses) {
            $qGen = new QueryGenerator();
            foreach ($clauses as $clause) {
               $qGen->$clause('clause');
            }

            $build = function() use ($qGen) {
               return $qGen->build();
            };
            $buildIncomplete = function() use ($qGen) {
               return $qGen->buildIncomplete();
            };

            $this->assertTrue((bool)$this->didThrowException($build));
            $this->assertFalse($this->didThrowException($buildIncomplete));
         }
      }
   }

   public function testCompleteQuery() {
      $completeQueryClauseSets = [
         'select' => [['select', 'from']],
         'insert' => [
            ['insert', 'set'],
            ['insert', 'columns', 'values'],
         ],
         'replace' => [
            ['replace', 'set'],
            ['replace', 'columns', 'values'],
         ],
         'update' => [['update', 'set']],
         'delete' => [['delete', 'from']],
      ];

      foreach ($completeQueryClauseSets as $clauseSets) {
         foreach ($clauseSets as $clauses) {
            $qGen = new QueryGenerator();
            foreach ($clauses as $clause) {
               $qGen->$clause('clause');
            }

            $build = function() use ($qGen) {
               return $qGen->build();
            };
            $buildIncomplete = function() use ($qGen) {
               return $qGen->buildIncomplete();
            };

            $this->assertFalse($this->didThrowException($build));
            $this->assertFalse($this->didThrowException($buildIncomplete));
         }
      }
   }

   public function testSmallQuery() {
      $qGen = new QueryGenerator();
      $qGen->select(['field']);
      $qGen->from(['table']);

      $expectedQuery = <<<EOT
SELECT field
FROM table
EOT;
      $expectedParams = [];

      $this->assertQuery($qGen, $expectedQuery, $expectedParams);
   }

   public function testBigQuery() {
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

      $this->assertQuery($qGen, $expectedQuery, $expectedParams);
   }

   public function testSingleArguments() {
      $qGen = new QueryGenerator();
      $qGen->select('field');
      $qGen->from('table');

      $expectedQuery = <<<EOT
SELECT field
FROM table
EOT;
      $expectedParams = [];

      $this->assertQuery($qGen, $expectedQuery, $expectedParams);
   }

   public function testModifiers() {
      $qGen = new QueryGenerator();
      $qGen->insert('table');
      $qGen->set('field = ?', 0);
      $qGen->modify('IGNORE');

      $expectedQuery = <<<EOT
INSERT IGNORE INTO table
SET field = ?
EOT;
      $expectedParams = [0];

      $this->assertQuery($qGen, $expectedQuery, $expectedParams);
   }

   public function assertQuery($qGen, $expectedQuery, $expectedParams) {
      list($actualQuery, $actualParams) = $qGen->build();
      $this->assertEquals($actualQuery, $expectedQuery);
      $this->assertEquals($actualParams, $expectedParams);

      list($actualQuery, $actualParams) = $qGen->buildIncomplete();
      $this->assertEquals($actualQuery, $expectedQuery);
      $this->assertEquals($actualParams, $expectedParams);
   }

   public function didThrowException($callback) {
      try {
         $callback();
         return false;
      } catch (MissingClauseException $e) {
         return $e->getMessage() ?: true;
      }
   }
}
