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
         'clause' => 'SELECT <<MODIFIERS>> ',
         'prefix' => '',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'insert' => [
         'clause' => 'INSERT <<MODIFIERS>> INTO ',
         'prefix' => '',
         'glue' => ', ',
         'suffix' => '',
      ],
      'replace' => [
         'clause' => 'REPLACE <<MODIFIERS>> INTO ',
         'prefix' => '',
         'glue' => ', ',
         'suffix' => '',
      ],
      'update' => [
         'clause' => 'UPDATE <<MODIFIERS>> ',
         'prefix' => '',
         'glue' => ', ',
         'suffix' => '',
      ],
      'delete' => [
         'clause' => 'DELETE <<MODIFIERS>> ',
         'prefix' => '',
         'glue' => ', ',
         'suffix' => '',
      ],
      'from' => [
         'clause' => 'FROM ',
         'prefix' => '',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'join' => [
         'clause' => '',
         'prefix' => '',
         'glue' => "\n",
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'set' => [
         'clause' => 'SET ',
         'prefix' => '',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'columns' => [
         'clause' => '',
         'prefix' => '(',
         'glue' => ', ',
         'suffix' => ')',
         'requiresArgument' => true,
      ],
      'values' => [
         'clause' => 'VALUES ',
         'prefix' => '(',
         'glue' => '), (',
         'suffix' => ')',
         'requiresArgument' => true,
      ],
      'where' => [
         'clause' => 'WHERE ',
         'prefix' => '(',
         'glue' => ') AND (',
         'suffix' => ')',
         'requiresArgument' => true,
      ],
      'group' => [
         'clause' => 'GROUP BY ',
         'prefix' => '',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'having' => [
         'clause' => 'HAVING ',
         'prefix' => '(',
         'glue' => ') AND (',
         'suffix' => ')',
         'requiresArgument' => true,
      ],
      'order' => [
         'clause' => 'ORDER BY ',
         'prefix' => '',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'limit' => [
         'clause' => 'LIMIT ',
         'prefix' => '',
         'glue' => '',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'offset' => [
         'clause' => 'OFFSET ',
         'prefix' => '',
         'glue' => '',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'as' => [
         'clause' => 'AS ',
         'prefix' => '',
         'glue' => false,
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'duplicate' => [
         'clause' => 'ON DUPLICATE KEY UPDATE ',
         'prefix' => '',
         'glue' => ', ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'modify' => [
         'clause' => '',
         'prefix' => '',
         'glue' => ' ',
         'suffix' => '',
         'requiresArgument' => true,
      ],
      'forupdate' => [
         'clause' => 'FOR UPDATE',
         'prefix' => '',
         'glue' => '',
         'suffix' => '',
         'requiresArgument' => false,
      ],
   ];

   /**
    * The keys of this array are the primary clauses that can be present in a
    * MySQL query. Each primary clause has a set of valid sub-clauses that can
    * be present in a completed query of that type.
    */
   private static $possibleClauses = [
      'select' => ['from', 'join', 'where', 'group', 'having', 'order', 'limit', 'offset', 'forupdate'],
      'insert' => ['set', 'columns', 'values', 'duplicate', 'as'],
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
   private $useOr;

   public function __construct() {
      $this->clauses = [];
      $this->params = [];

      foreach (array_keys(self::$methods) as $method) {
         $this->clauses[$method] = [];
         $this->params[$method] = [];
      }

      $this->validateQuery = true;
      $this->useOr = false;
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

      if ($clauses instanceOf QueryGenerator) {
         $clauses->skipValidation();
         list($clauses, $params) = $clauses->build(/* $skipClauses = */ true);
      }

      if (!is_array($clauses)) {
         $clauses = [$clauses];
      }

      if (!is_array($params)) {
         $params = [$params];
      }

      if (self::$methods[$method]['glue'] === false && count($this->clauses[$method]) > 1) {
         throw new Exception("Only one '$method()' is allowed per query");
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
    * @param $skipClauses : Exclude the 'clause' part (WHERE, SELECT, FROM,
    *                       ...) of each sub-expression. See constructClause
    *                       for more info. This is mostly for internal usage.
    *
    * Returns an array containing the query and paramter list, respectively.
    */
   public function build($skipClauses = false) {
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

         $clauses[] = $this->constructClause($method, $skipClauses);
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
    * Use OR when joining where conditions
    */
   public function &useOr() {
      $this->useOr = true;
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
    * Return a string of the specified SQL clause using its syntax rules,
    * optionally excluding the clause part (i.e. WHERE, SELECT, ...)
    *
    * Example:
    *    given where clauses 'foo = ?' and 'bar != ?'
    *    constructClause('where') => 'WHERE (foo = ?) AND (bar != ?)'
    *    constructClause('where', false) => '(foo = ?) AND (bar != ?)'
    */
   private function constructClause($method, $skipClause = false) {
      $clauseInfo = self::$methods[$method];
      $prefix = $clauseInfo['prefix'];
      $clause = $clauseInfo['clause'];

      if ($skipClause) {
         $clause = '';
      // The assumed precondition is that modify's prefix element will never
      // contain the substring '<<MODIFIERS>>'.
      } else if (strpos($clause, '<<MODIFIERS>>') !== false) {
         $clause = str_replace('<<MODIFIERS>>', $this->constructClause('modify'), $clause);
         // If there are no modifiers to apply we end up with an extra space
         // after the primary verb.
         $clause = str_replace('  ', ' ', $clause);
      }

      $suffix = $clauseInfo['suffix'];
      $glue = $this->getGlue($method);
      $pieces = implode($glue, $this->clauses[$method]);
      return "$clause$prefix$pieces$suffix";
   }

   /**
    * return the appropriate glue string for the given clause, taking into
    * account $this->useOr
    */
   private function getGlue($method) {
      if ($method !== 'where' || !$this->useOr) {
         return self::$methods[$method]['glue'];
      } else {
         return ") OR (";
      }
   }

   /**
    * Debug method to print out the full query with the params inserted at the correct locations
    */
   public function debugStringifyQuery(): string {
      [$query, $params] = $this->build();
      $paramValues = [];
      for ($i = 0; $i < count($params); $i += 2) {
         $type = $params[$i];
         $value = $params[$i + 1];

         $typedValue = (function() use ($value, $type) {
            switch ($type) {
               case 'i': // matches: define('T_I', 'i'); => Integer / boolean
                  return (int)$value;
               case 'd': // matches: define('T_D', 'd'); => Float / Double
                  return (float)$value;
               case 's': // matches: define('T_S', 's'); => String / Character
                  return "'$value'";
               case 'j': // matches: define('T_J', 'j'); => Json
                  $value = addslashes(json_encode($value));
                  return "'$value'";
               case 'e': // matches: define('T_E', 'e'); => Enum
                  if ($value instanceof Enum) {
                     return $value->getValue();
                  } else if ($value instanceof BackedEnum) {
                     return $value->value;
                  } else {
                     return "'$value'";
                  }
               case 'b': // matches: define('T_B', 'b'); => Binary
               case 'a': // matches: define('T_A', 'a'); => Opt-out of type enforcement
               default:
                  return $value;
            }
         })();
         $paramValues[] = $typedValue;
      }
      $query = str_replace('?', '%s', $query);
      $query = vsprintf($query, $paramValues);
      return $query;
   }
}

class MissingClauseException extends Exception {}
class MissingPrimaryClauseException extends MissingClauseException {}
class MissingRequiredClauseException extends MissingClauseException {}
