<?

/**
 * A simple query generator for conditionally building MySQL SELECT statements.
 *
 * Build clauses by calling their corresponding member functions.
 *
 * Each method takes two arguments:
 * 1. An array of strings that will later be combined to form the clause.
 * 2. An array of paramters for use in prepared statements.
 *
 * Available clauses / methods are:
 *  select, from, join, where, group, having, order, limit, offset
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
   // The keys of this array are the set of clauses that can compose a SELECT
   // statement. These correspond to methods that can be called on this class.
   // The values are the syntax rules for collapsing the corresponding clauses.
   private static $methods = [
      'select' => [
         'prefix' => 'SELECT ',
         'glue' => ', ',
         'suffix' => ''
      ],
      'from' => [
         'prefix' => 'FROM ',
         'glue' => ', ',
         'suffix' => ''
      ],
      'join' => [
         'prefix' => '',
         'glue' => "\n",
         'suffix' => ''
      ],
      'where' => [
         'prefix' => 'WHERE (',
         'glue' => ') AND (',
         'suffix' => ')'
      ],
      'group' => [
         'prefix' => 'GROUP BY ',
         'glue' => ', ',
         'suffix' => ''
      ],
      'having' => [
         'prefix' => 'HAVING (',
         'glue' => ') AND (',
         'suffix' => ')'
      ],
      'order' => [
         'prefix' => 'ORDER BY ',
         'glue' => ', ',
         'suffix' => ''
      ],
      'limit' => [
         'prefix' => 'LIMIT ',
         'glue' => '',
         'suffix' => ''
      ],
      'offset' => [
         'prefix' => 'OFFSET ',
         'glue' => '',
         'suffix' => ''
      ]
   ];

   private $clauses;
   private $params;

   public function __construct() {
      $this->clauses = [];
      $this->params = [];

      foreach (self::$methods as $method => $_) {
         $this->clauses[$method] = [];
         $this->params[$method] = [];
      }
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

      list($clauses, $params) = $args;
      $clauses = $clauses ?: [];
      $params = $params ?: [];

      $this->clauses[$method] = array_merge($this->clauses[$method], $clauses);
      $this->params[$method] = array_merge($this->params[$method], $params);

      return $this;
   }

   /**
    * Combine the clauses and paramters in this QueryGenerator to compose a
    * complete query and paramter list.
    * Returns an array containing the query and paramter list, respectively.
    */
   public function build() {
      $select = $this->clauses['select'];
      $from = $this->clauses['from'];
      if (!$select && !$from) {
         throw new Exception('Query must have SELECT and FROM clauses.');
      } else if (!$select) {
         throw new Exception('Query must have a SELECT clause.');
      } else if (!$from) {
         throw new Exception('Query must have a FROM clause.');
      }

      $clauses = $params = [];
      foreach (self::$methods as $method => $_) {
         if ($this->clauses[$method]) {
            $clauses[] = $this->collapse($method);
            $params = array_merge($params, $this->params[$method]);
         }
      }
      return [implode("\n", $clauses), $params];
   }

   /**
    * Return a string of the specified SQL clause using its syntax rules.
    *
    * Example:
    *    given where clauses 'foo = ?' and 'bar != ?'
    *    collapse('where') => 'WHERE (foo = ?) AND (bar != ?)'
    */
   private function collapse($method) {
      $prefix = self::$methods[$method]['prefix'];
      $suffix = self::$methods[$method]['suffix'];
      $glue = self::$methods[$method]['glue'];
      return $prefix . implode($glue, $this->clauses[$method]) . $suffix;
   }
}
