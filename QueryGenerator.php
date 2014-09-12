<?php

/**
 * A simple query generator for conditionally building MySQL statements.
 *
 * Build clauses by calling their corresponding member functions.
 *
 * Each method takes two arguments:
 * 1. An array of strings that will later be combined to form the clause.
 * 2. An array of paramters for use in prepared statements.
 *
 * Available clauses / methods are:
 *  select, insert, replace, update, delete, from, join, set, columns, values,
 *  where, group, having, order, limit, offset, duplicate, modify
 *
 * After all clauses have been built, call the 'build' member function to
 * compose the entire query. This returns an array containing the query and
 * an array parameters.
 *
 * Example usage:
 *    $qGen = new QueryGenerator();
 *    $qGen->select(['field1', 'field2']);
 *    $qGen->from(['table1']);
 *    $qGen->join(['INNER JOIN table2 ON asdf = ?'], 'asdf');
 *    $qGen->where(['condition1 < ?', 'condition2 > ?'], [5, 7]);
 *    list($query, $params) = $qGen->build();
 *
 * Generated query:
 *    SELECT field1, field2
 *    FROM table1
 *    INNER JOIN table2 ON asdf = ?
 *    WHERE (condition1 < ?) AND (condition2 > ?)
 *
 * Generated parameters:
 *    ['asdf', 5, 7]
 */
class QueryGenerator {
   /**
    * The keys of this array are the set of clauses that can compose different
    * statements. These correspond to methods that can be called on this class.
    * The values are the syntax rules for collapsing the corresponding clauses.
    */
   private static $methods = [
      'select' => [
         'prefix' => 'SELECT <<MODIFIERS>> ',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'insert' => [
         'prefix' => 'INSERT <<MODIFIERS>> INTO ',
         'glue' => ', ',
         'suffix' => '',
      ],
      'replace' => [
         'prefix' => 'REPLACE <<MODIFIERS>> INTO ',
         'glue' => ', ',
         'suffix' => '',
      ],
      'update' => [
         'prefix' => 'UPDATE <<MODIFIERS>> ',
         'glue' => ', ',
         'suffix' => '',
      ],
      'delete' => [
         'prefix' => 'DELETE <<MODIFIERS>> ',
         'glue' => ', ',
         'suffix' => '',
      ],
      'from' => [
         'prefix' => 'FROM ',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'join' => [
         'prefix' => '',
         'glue' => "\n",
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'set' => [
         'prefix' => 'SET ',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'columns' => [
         'prefix' => '',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'values' => [
         'prefix' => 'VALUES (',
         'glue' => '), (',
         'suffix' => ')',
         'requiresArgument' => true,
      ],
      'where' => [
         'prefix' => 'WHERE (',
         'glue' => ') AND (',
         'suffix' => ')',
         'requiresArgument' => true,
      ],
      'group' => [
         'prefix' => 'GROUP BY ',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'having' => [
         'prefix' => 'HAVING (',
         'glue' => ') AND (',
         'suffix' => ')',
         'requiresArgument' => true,
      ],
      'order' => [
         'prefix' => 'ORDER BY ',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'limit' => [
         'prefix' => 'LIMIT ',
         'glue' => '',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'offset' => [
         'prefix' => 'OFFSET ',
         'glue' => '',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'duplicate' => [
         'prefix' => 'ON DUPLICATE KEY UPDATE ',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'modify' => [
         'prefix' => '',
         'glue' => ' ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
   ];

   /**
    * The keys of this array are the primary clauses that can be present in a
    * MySQL query. Each primary clause has a set of valid sub-clauses that can
    * be present in a completed query of that type.
    */
   private static $possibleClauses = [
      'select' => ['from', 'join', 'where', 'group', 'having', 'order', 'limit', 'offset'],
      'insert' => ['set', 'columns', 'values', 'duplicate'],
      'replace' => ['set', 'columns', 'values'],
      'update' => ['set', 'where', 'order', 'limit'],
      'delete' => ['from', 'where', 'order', 'limit'],
   ];

   /**
    * Each query type can be formatted in a number of ways according to
    * different sub-trees of its grammar. Each element in the value arrays of
    * this array correspond to the minimum required set of sub-clauses needed
    * in each of these grammar sub-trees. A query will be considered complete
    * if it has all the sub-clauses listed in any of these sets.
    */
   private static $minimumClauses = [
      'select' => [['from']],
      'insert' => [['set'], ['columns', 'values']],
      'replace' => [['set'], ['columns', 'values']],
      'update' => [['set']],
      'delete' => [['from']],
   ];

   /**
    * Each query type can specify a certain selection of modifiers. They each
    * change some aspect of how the query runs.
    */
   private static $queryModifiers = [
      'select' => [
         'ALL', 'DISTINCT', 'DISTINCTROW',
         'HIGH_PRIORITY',
         'STRAIGHT_JOIN',
         'SQL_SMALL_RESULT', 'SQL_BIG_RESULT', 'SQL_BUFFER_RESULT',
         'SQL_CACHE', 'SQL_NO_CACHE',
         'SQL_CALC_FOUND_ROWS'
      ],
      'insert' => ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE'],
      'replace' => ['LOW_PRIORITY', 'DELAYED'],
      'update' => ['LOW_PRIORITY', 'IGNORE'],
      'delete' => ['LOW_PRIORITY', 'QUICK', 'IGNORE'],
   ];

   private $clauses;
   private $params;
   private $validateQuery;

   public function __construct() {
      $this->clauses = [];
      $this->params = [];

      foreach (array_keys(self::$methods) as $method) {
         $this->clauses[$method] = [];
         $this->params[$method] = [];
      }

      $this->validateQuery = true;
   }

   /**
    * Append the given clause components and parameters to their existing
    * counterparts for the specified clause.
    */
   public function &__call($method, $args) {
      $method = strtolower($method);

      if (!isset(self::$methods[$method])) {
         throw new Exception("Method \"$method\" does not exist.");
      }

      $requiresArgument = (isset(self::$methods[$method]['requiresArgument']) ?
       self::$methods[$method]['requiresArgument'] : false);

      if ($requiresArgument && count($args) < 1) {
         throw new Exception("Missing argument 1 (\$clauses) for $method()");
      } else if (count($args) < 2) {
         $clauses = reset($args);
         $params = [];
      } else {
         list($clauses, $params) = $args;
      }

      if (!is_array($clauses)) {
         $clauses = [$clauses];
      }

      if (!is_array($params)) {
         $params = [$params];
      }

      $this->clauses[$method] = array_merge($this->clauses[$method], $clauses);
      $this->params[$method] = array_merge($this->params[$method], $params);

      return $this;
   }

   /**
    * Combine the clauses and parameters in this QueryGenerator to compose a
    * complete query and paramter list.
    *
    * Incomplete queries will cause a MissingClauseException to be thrown
    * (one of MissingPrimaryClauseException or MissingRequiredClauseException)
    * unless `skipValidation` has been called.
    *
    * Returns an array containing the query and paramter list, respectively.
    */
   public function build() {
      if ($this->validateQuery) {
         $this->assertCompleteQuery();
      }

      $setMethods = $this->getSetMethods();

      $clauses = $params = [];
      foreach (array_keys(self::$methods) as $method) {
         // Modifiers are handled automatically by constructClause.
         if ($method == 'modify') {
            continue;
         }

         // Because we are indiscriminantly interating over every possible
         // clause we need to verify that each clause we use has been set.
         if (!isset($setMethods[$method])) {
            continue;
         }

         $clauses[] = $this->constructClause($method);
         $params = array_merge($params, $this->params[$method]);
      }
      return [implode("\n", $clauses), $params];
   }

   /**
    * Bypass query validation when building.
    */
   public function &skipValidation() {
      $this->validateQuery = false;
      return $this;
   }

   /**
    * Assert the completeness of this QueryGenerator instance by verifying
    * that all required clauses have been set.
    */
   private function assertCompleteQuery() {
      $primaryMethod = $this->getPrimaryMethod();

      if (!$primaryMethod) {
         $primaryClauseStr = implode("', '", $this->getPrimaryClauses());
         throw new MissingPrimaryClauseException(
          "Missing primary clause. One of '$primaryClauseStr' needed.");
      }

      $minimumClauses = self::$minimumClauses[$primaryMethod];

      $setMethods = $this->getSetMethods();
      foreach ($minimumClauses as $option) {
         $intersection = array_intersect($option, $setMethods);
         // We will want to compare this array to another for set equality,
         // so we need to throw away arbitrary ordering.
         sort($option);
         sort($intersection);

         // A matching minimum set was found.
         if ($option == $intersection) {
            return;
         }
      }

      $requiredClauseOptions = array_map(function($option) {
         return "'" . implode("', '", $option) . "'";
      }, $minimumClauses);
      $requiredClauseStr = '{' . implode('}, {', $requiredClauseOptions) . '}';
      throw new MissingRequiredClauseException(
       "Missing required clauses. One of $requiredClauseStr needed.");
   }

   /**
    * Return the list of primary query clauses.
    */
   private static function getPrimaryClauses() {
      return array_keys(self::$possibleClauses);
   }

   /**
    * Return the primary clause in this QueryGenerator instance.
    * If multiple primary clauses have been set, all but the first set clause
    * will be ignored.
    */
   private function getPrimaryMethod() {
      $primaryClauses = self::getPrimaryClauses();
      $setMethods = $this->getSetMethods();
      $setPrimaryClauses = array_intersect($primaryClauses, $setMethods);
      return reset($setPrimaryClauses);
   }

   private function getSetMethods() {
      $methods = array_keys(array_filter($this->clauses));
      return array_combine($methods, $methods);
   }

   /**
    * Return a string of the specified SQL clause using its syntax rules.
    *
    * Example:
    *    given where clauses 'foo = ?' and 'bar != ?'
    *    constructClause('where') => 'WHERE (foo = ?) AND (bar != ?)'
    */
   private function constructClause($method) {
      $prefix = self::$methods[$method]['prefix'];

      // The assumed precondition is that modify's prefix element will never
      // contain the substring '<<MODIFIERS>>'.
      if (strpos($prefix, '<<MODIFIERS>>') !== false) {
         $prefix = str_replace('<<MODIFIERS>>', $this->constructClause('modify'), $prefix);
         // If there are no modifiers to apply we end up with an extra space
         // after the primary verb.
         $prefix = str_replace('  ', ' ', $prefix);
      }

      $suffix = self::$methods[$method]['suffix'];
      $glue = self::$methods[$method]['glue'];
      $pieces = implode($glue, $this->clauses[$method]);
      return "$prefix$pieces$suffix";
   }
}

class MissingClauseException extends Exception {}
class MissingPrimaryClauseException extends MissingClauseException {}
class MissingRequiredClauseException extends MissingClauseException {}
