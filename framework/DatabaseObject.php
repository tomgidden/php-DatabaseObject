<?php
/**
 * Database Abstraction Layer Framework
 *
 * @author      Tom Gidden <tom@gidden.net>
 * @copyright   Tom Gidden 2009
 * @version     $Id$
 * @package     DatabaseObject
 * @subpackage  framework
 * @filesource
 */

/**
 * Includes
 */
require_once('DatabaseObject/common/vars.php');
require_once('DatabaseObject/framework/DatabaseHandle.php');
require_once('DatabaseObject/framework/DatabaseObjectDefinition.php');
require_once('DatabaseObject/framework/DatabaseArray.php');
require_once('DatabaseObject/framework/JavascriptSerialisable.php');

/**
 * Exception thrown when more than one result is found for a query that
 * should return only one: For example, by {@link get_one_by_criteria()}
 *
 * @package     DatabaseObject
 * @subpackage  framework
 */
class TooManyResultsException extends Exception {};


/**
 * Exception thrown when a bad set of search criteria are supplied to a query.
 *
 * @package     DatabaseObject
 * @subpackage  framework
 */
class BadCriteriaException extends Exception {};


/**
 * Exception thrown when a bad parameter is supplied to a query.
 *
 * @package     DatabaseObject
 * @subpackage  framework
 */
class BadParameterException extends Exception {};


/**
 * Exception thrown when the PHP-represented model definition doesn't seem
 * to match the actual database schema in ways, especially with regards to
 * one-to-many data on one-to-one links.
 *
 * @package     DatabaseObject
 * @subpackage  framework
 */
class ModelIncompatibilityException extends Exception {};


/**
 * Base class for all database-backed model objects.
 *
 * This framework allows simple SQL objects to be represented easily in
 * PHP, by doing all of the retrieve functions.
 *
 * The framework itself is quite complex, because the data classes
 * themselves (extended from DatabaseObject) shouldn't carry around their
 * definitions as instance variables.  Instead, the definitions are stored
 * in separate classes (extended from DatabaseObjectDefinition) which are
 * accessed through a singleton.
 *
 * The principle is that an implementing object (such as, say, User) can
 * be as simple as an empty class extending DatabaseObject, and a
 * separate class (extending DatabaseObjectDefinition) specifies the
 * columns and links of that object.
 *
 * For example:
 * <pre>
 *   class User extends DatabaseObject {};
 *   class User_defn extends DatabaseObjectDefinition {
 *     public $classname = 'User';     // The class of the DatabaseObject
 *     public $tablename = 'user';     // The name of the SQL base table
 *     public $idcolumns = 'user_id';  // The columns to use to identify this object.
 *     public $links = array('org'=>array('nullable'=>false,
 *                           'classname'=>'Org',
 *                           'foreign_key'=>'org_id'));
 *     public $columns = array('user_id'=>true,
 *                             'name'=>true,
 *                             'password'=>true,
 *                             'org_id'=>true);
 *   };
 *   $user = DatabaseObject::get_by_id('User', 501);
 * </pre>
 *
 * Rather than using a traditional constructor, the static get_* methods
 * should be used.
 *
 * @package     DatabaseObject
 * @subpackage  framework
 */
abstract class DatabaseObject implements JavascriptSerialisable, IteratorAggregate, ArrayAccess {

  /**
   * The actual associative array containing the object's properties and links.
   *
   * The _data array stores the data for the object as defined in its
   * DatabaseObjectDefinition.  In normal circumstances, this should be
   * completely hidden as the accessor mechanisms ({@link offsetGet()}
   * and {@link offsetSet()}) should be used instead.  However, these
   * methods incur an overhead and also may perform an unwanted retrieval.
   * In short, care should be taken when used.
   *
   * @var array
   */
  protected $_data = array();



  /**
   * Modification flag for the object.
   *
   * If the object has been modified since creation, this flag will be set
   * to true.  This is primarily used by the {@link save()} method to
   * determine whether or not to perform the database change.
   *
   * Changing this flag is not recommended.
   *
   * @var boolean
   */
  public $modified = false;



  /**
   * New (unsaved) flag for the object.
   *
   * If the object was instantiated as a new object (as opposed to the
   * result of a retrieval from the database), it will be marked as
   * 'brandnew'.  This <i>often</i> implies the object does not (yet)
   * exist in the database, although that can often be false.  This is
   * used by the {@link save()} method to change the SQL call to an INSERT
   * (or REPLACE) rather than an UPDATE.
   *
   * Changing this flag is not recommended.
   *
   * @var boolean
   */
  public $brandnew = true;



  /**
   * A cache of all retrieval queries for all DatabaseObjects.
   *
   * @var array
   * @ignore
   */
  protected static $_queries = array();



  /**
   * (Currently) no-effect object constructor stub.
   *
   * Most construction will be done by the static get_* methods.
   */
  protected function __construct() {
  }



  /**
   * Retrieves an array of these objects by a set of simple criteria.
   *
   * This is a general-purpose query mechanism for a simple set of
   * criteria.  $criteria should consist of a list of
   * column/condition-to-value mappings to add to the WHERE clause.  They
   * will be prefixed by the base object name.
   *
   * Since it's a static method, we don't know what type the class was run
   * from as it is resolved at compile-time on DatabaseObject.  Instead,
   * just do it as part of {@link DatabaseObject}, using $classname to
   * locate the class.
   *
   * $limit_overrides allows the programmer to override certain 'limit'
   * settings for given objects.  This allows more efficient querying in
   * given scenarios.
   *
   * This method will return an array or throw an exception.  The array
   * may contain one, more than one, or no elements.  If a single object
   * is expected, {@link get_one_by_criteria()} may prove easier to use.
   *
   * Examples:
   * <pre>
   *    $users = DatabaseObject::get_by_criteria('User',
   *                                             array('User.username=?'=>'gid'));
   *
   *    $ps = DatabaseObject::get_by_criteria('Particulars',
   *                                          array('Particulars.org_id=?'=>6,
   *                                                'Particulars_status_type.archived=?'=>1));
   * </pre>
   *
   * @return array
   */
  public static function get_by_criteria($classname, $criteria, $limit_overrides=null, $orderby=null, $groupby=null) {

    // Get the object definition for the class.
    if(is_null($defn = DatabaseObjectDefinition::get_instance($classname)))
      throw new Exception("Definition for '$classname' not found");

    // The map must exist as an array.  Use get_all if you want all
    // results (you maniac!)
    if(!is_array($criteria))
      throw new BadCriteriaException("Invalid criteria map");

    // We will need to use a query to extract the information from the
    // database.  Since many objects may be constructed, we cache the
    // query in DatabaseObject::$_queries.

    // If there are no criteria, then we fake it.
    if(sizeof($criteria)<1) {
      $criteria = null;
      $query_name = $classname.'__ALL';
    } else {
      $query_name = $classname.'__';
      foreach ($criteria as $key=>$val) {
        if(is_int($key))
          $query_name .= $val.'/';
        else
          $query_name .= $key.'/';
      }
    }

    if(!empty($orderby)) $query_name .= '_O='.$orderby;

    if(!empty($groupby)) $query_name .= '_G='.$groupby;

    // Check to see if the query has already been built.
    if(isset(self::$_queries[$query_name])) {
      // Yes, it has, so retrieve it from the cache.
      $sql = self::$_queries[$query_name];

    } else {
      // No, it's not in the cache, so build it.  clauses() used in its
      // default state will return components for a query which should
      // retrieve everything.  Note, we throw away the $where section, as
      // the clauses() call returns those for a normal get_by_id() query.
      list($cols, $from) = $defn->clauses($limit_overrides);

      // Now we need to build the unique WHERE clause.  We will build the
      // array of values at the same time.

      // Initialise the buffer: $wheres will contain individual WHERE
      // clauses to be ANDed together.
      $wheres = array();

      // Loop each criteria.  In this simple implementation, we will just
      // handle equality.
      if(!is_null($criteria)) {
        foreach ($criteria as $condition=>$value) {
#         print '('.var_export($condition,true).'=>'.var_export($value,true)."),\n";
          if(is_string($condition))
            $wheres[] = $condition;
          else if(is_int($condition) and is_string($value))
            $wheres[] = $value;
        }
      }

      // The SQL is still quite simple.
      if($wheres)
        $sql = 'SELECT '.$cols.' FROM '.$from.' WHERE '.join(' AND ', $wheres);
      else
        $sql = 'SELECT '.$cols.' FROM '.$from;

      // Add the ORDER BY clause if necessary
      if(!empty($orderby))
        $sql .= ' ORDER BY '.$orderby;

      // Add the GROUP BY clause if necessary
      if(!empty($groupby))
        $sql .= ' GROUP BY '.$groupby;

      // Cache the query for future use.
      self::$_queries[$query_name] = $sql;
    }

    // Execute the query, using the SQL and the primary keys.
    if(!is_null($criteria)) {

      // Filter out all non-key/value criteria, as they aren't parameterized
      $params = array();
      foreach ($criteria as $condition=>$value) {
        if(is_string($condition)) {
          // This is key/value
          if(is_scalar($value))
            // Simple scalar param
            $params[] = $value;
          else if(is_null($value))
            // NULL param, which is okay
            $params[] = null;
          else if(is_array($value)) {
            // This is an array of params, which is for when things like
            // BETWEEN ? AND ? are used.
            foreach ($value as $val) {
              // Add each param
              $params[] = $val;
            }
          }
          else if(is_object($value))
            // The value is an object (bad)
            throw new BadParameterException("The parameter for criteria '$condition' is not a scalar or an array, but a '".get_class($value)."' object.");
          else
            // The value is something else
            throw new BadParameterException("The parameter for criteria '$condition' is not a scalar or an array, but a '".gettype($value)."'");
        }
      }

      $q = DatabaseHandle::get_instance()->query_with_exceptions($sql, $params);
    }
    else
      $q = DatabaseHandle::get_instance()->query_with_exceptions($sql);

    // Parse the results.  This will build an array, as we do not know how
    // many base objects will be returned by this query.
    $results = array();

    // Each row can represent an individual base object, or additional
    // subobject on a previous base object in a one-to-many link.
    while($row = $q->fetch(PDO::FETCH_NUM)) {
      // $pointer is the location of the "cursor" through the row.  We
      // should only move forward with this.
      $pointer = 0;

      // Build our candidate object, which may or may not be used.
      $newobj = new $classname();

      // Load the column data into the base object.  $pointer will get updated.
      $newobj->consume_row_columns($defn, $row, $pointer);

      // We need to work out if the object already exists (in which case,
      // this is just additional one-to-many subobjects).  To do this, we
      // see if the primary key for this new object is in the result array
      // already.
      $pks = $newobj->serialised_primary_keys();

      // If it isn't in the results set, then put the new object there as
      // we'll be using it after all.
      if(!isset($results[$pks]))
        $results[$pks] = $newobj;

      // Now we must process the subobjects.  If the new object wasn't
      // unique, then we forget about it from now on... it'll get
      // overwritten on the next row, or it will go out-of-scope when this function exists.
      $results[$pks]->consume_row_links($defn, $row, $pointer, 0, $limit_overrides);

      // If the object has a special post-processing method for SELECTs,
      // then run it.
      if(method_exists($results[$pks], 'post_select'))
        $results[$pks]->post_select();

      // Mark the result as unmodified
      $results[$pks]->modified = false;
      $results[$pks]->brandnew = false;
    }

    // Return the result array.
    return $results;
  }



  /**
   * Retrieves a single object by a simple set of criteria.
   *
   * This function uses {@link get_by_criteria()} to return an array of
   * {@link DatabaseObject}s and then returns the only member if there is
   * one.  If there isn't one, then an Exception in thrown.  It is the
   * calling program's responsibility to make sure the criteria are narrow
   * enough to return a single result.
   *
   * Example:
   * <pre>
   *    try {
   *      $user = DatabaseObject::get_one_by_criteria('User',
   *                                                  array('User.username=?'=>'gid'));
   *    }
   *    catch (TooManyResultsException $e) {
   *      print "Too many users found";
   *      exit();
   *    }
   * </pre>
   *
   * @return DatabaseObject
   */
  public static function get_one_by_criteria($classname, $criteria, $limit_overrides=null) {

    // Perform the search
    $arr = self::get_by_criteria($classname, $criteria, $limit_overrides);

    // Decide what to do based on the number of results.
    switch(count($arr)) {
    case 0: return null;            // No results, so return null
    case 1: return current($arr);   // There is only one result, so return it.
    default:
      // There was more than one result, so ::get_one_by_criteria is
      // inappropriate.  Check the calling program.
      throw new TooManyResultsException("More than one result found for $classname::get_one_by_criteria()");
    }
  }



  /**
   * Retrieves a single object by the primary key alone.
   *
   * This is effectively a constructor for the object, specifying that
   * it should be retrieved by primary key.
   *
   * Since it's a static method, we don't know what type the class was run
   * from (or do we?) so just do it as part of {@link DatabaseObject},
   * using $classname to locate the class.
   *
   * $ids can be a scalar (when the object has a single primary key) or
   * an array (when the object has an aggregate primary key)
   *
   * If defined('MEMCACHE_USE'), then the object will be retrieved from the
   * memcache if found, and will be added to the memcache if not found.
   * This will happen if $memcache_timeout>0 or the object definition
   * has {@link DatabaseObjectDefinition::$memcache_timeout} set.
   *
   * Examples:
   * <pre>
   *     $user = DatabaseObject::get_by_id('User', 501);
   *     $foo = DatabaseObject::get_by_id('Bar', array(501, 10));
   * </pre>
   *
   * @return DatabaseObject
   */
  public static function get_by_id($classname, $ids, $memcache_timeout=false) {

    // Get the object definition for the class.
    if(is_null($defn = DatabaseObjectDefinition::get_instance($classname)))
      throw new Exception("Definition for '$classname' not found");

    // First, check the memcache
    if(defined('MEMCACHE_USE') and MEMCACHE_USE) {

      // If there's a memcache...
      global $MEMCACHE;
      if(!$MEMCACHE)
        // The memcache object doesn't exist, so disable it for the rest
        // of the call.
        $memcache_timeout = false;
      else {
        // If there wasn't an explicitly stated timeout, then use the
        // object's default.
        if(!$memcache_timeout and isset($defn->memcache_timeout))
          $memcache_timeout = $defn->memcache_timeout;

        // Look for instance in memcache, and return it if found.
        if($memcache_timeout)
          if($instance = $MEMCACHE->get($memcache_obj_key = MEMCACHE_PREFIX.$classname.':'.serialize($ids)))
            return $instance;
      }
    }

    // We will need to use a query to extract the information from the
    // database.  Since many objects may be constructed, we cache the
    // query in DatabaseObject::$_queries.
    $query_name = $classname.'__by_id';

    // Check to see if the query has already been built.
    if(isset(self::$_queries[$query_name])) {
      // Yes, it has, so retrieve it from the cache.
      $sql = self::$_queries[$query_name];

    } else {
      // Check the memcache for the SQL
      if($memcache_timeout and defined('MEMCACHE_USE') and MEMCACHE_USE)
        // Retrieve it if it's there.
        $sql = $MEMCACHE->get($memcache_sql_key = MEMCACHE_PREFIX.'SQL:'.$query_name);
    }

    if(!isset($sql) or empty($sql)) {
      // No, it's not in the cache, so build it.  clauses() used in its
      // default state will return components for a query which should
      // retrieve everything given the object's primary keys ($ids)
      list($cols, $from, $where) = $defn->clauses();

      // The SQL is quite simple here, as it is just a retrieval.
      // XXX: DISTINCT might be handy here...
      $sql = 'SELECT '.$cols.' FROM '.$from.' WHERE '.$where;

      // Cache the query for future use.
      self::$_queries[$query_name] = $sql;

      // and in memcache...
      if($memcache_timeout and defined('MEMCACHE_USE') and MEMCACHE_USE)
        $MEMCACHE->set($memcache_sql_key, $sql, false, $memcache_timeout);
    }

    // Execute the query, using the SQL and the primary keys.
    $q = DatabaseHandle::get_instance()->query_with_exceptions($sql, $ids);

    // Create the result object.
    $result = new $classname();

    // We're going to need to compare primary keys to make sure only one
    // object is returned, so we declare it here.
    unset($result_pks);

    // The object is represented by one OR MORE rows from the query: if
    // there are any one-to-many links, then more than one row will be
    // returned.
    while($row = $q->fetch(PDO::FETCH_NUM)) {
      // $pointer is the location of the "cursor" through the row.  We
      // should only move forward with this.
      $pointer = 0;

      // Load the results into the object.  $pointer will get updated in
      // this call.  $defn and $row are passed as references purely for
      // efficiency.
      $result->consume_row_columns($defn, $row, $pointer);

      // We need to make sure the primary keys are consistent, so we use
      // $result_pks to check.
      if(!isset($result_pks)) {
        // This is the first row, so we're fine at the moment.  Just store
        // the primary keys for the check on the next row.
        $result_pks = $result->serialised_primary_keys();

      } else {
        // This is a subsequent row, so we need to check that they are the
        // same.  If not, then the primary keys are not actually primary
        // keys, and we have a problem.
        $new_pks = $result->serialised_primary_keys();

        // Perform the check
        if($new_pks != $result_pks)
          throw new ModelIncompatibilityException("The primary keys for $classname are not complete, resulting in non-unique results");
      }

      // Now we need to traverse the definition to install subobjects
      $result->consume_row_links($defn, $row, $pointer, 0);
    }

    // If no result primary keys, then the query didn't return any rows.
    if(!isset($result_pks)) {
      if(method_exists($result, 'post_nullselect')) {
        // If the object has a special post-processing method for SELECTs
        // that return no results, then run it.
        $result->post_nullselect();

        // Treat it as a new object from now on
        $result->modified = false;
        $result->brandnew = true;
      }
      else {
        // Otherwise, just unset the object to return null (changed behaviour)
        unset($result);

        // Add a null to the memcache?
        if($memcache_timeout and defined('MEMCACHE_USE') and MEMCACHE_USE)
          $MEMCACHE->set($memcache_obj_key, null, false, $memcache_timeout);

        return null;
      }
    }
    else {
      // If the object has a special post-processing method for SELECTS,
      // then run it.
      if(method_exists($result, 'post_select'))
        $result->post_select();

      // Mark it as unmodified
      $result->modified = false;
      $result->brandnew = false;
    }

    // Add it to memcache if requested
    if($memcache_timeout and defined('MEMCACHE_USE') and MEMCACHE_USE)
      $MEMCACHE->set($memcache_obj_key, $result, false, $memcache_timeout);

    // Return the result: it should be fully constructed now.
    return $result;
  }



  /**
   * Returns an array of {@link DatabaseObject}s from a pre-existing query.
   *
   * Construct an array of results from a pre-existing query (which
   * should be compatible with the normal clauses for this object).
   *
   * Since it's a static method, we don't know what type the class was run
   * from as it is resolved at compile-time on DatabaseObject.  Instead,
   * just do it as part of {@link DatabaseObject}, using $classname to
   * locate the class.
   *
   * @return array
   */
  public static function get_from_query($classname, &$q) {

    // Get the object definition for the class.
    if(is_null($defn = DatabaseObjectDefinition::get_instance($classname)))
      throw new Exception("Definition for '$classname' not found");

    // Parse the results.  This will build an array, as we do not know how
    // many base objects will be returned by this query.
    $results = array();

    // Each row can represent an individual base object, or additional
    // subobject on a previous base object in a one-to-many link.
    while($row = $q->fetch(PDO::FETCH_NUM)) {
      // $pointer is the location of the "cursor" through the row.  We
      // should only move forward with this.
      $pointer = 0;

      // Build our candidate object, which may or may not be used.
      $newobj = new $classname();

      // Load the column data into the base object.  $pointer will get updated.
      $newobj->consume_row_columns($defn, $row, $pointer);

      // We need to work out if the object already exists (in which case,
      // this is just additional one-to-many subobjects).  To do this, we
      // see if the primary key for this new object is in the result array
      // already.
      $pks = $newobj->serialised_primary_keys();

      // If it isn't in the results set, then put the new object there as
      // we'll be using it after all.
      if(!isset($results[$pks]))
        $results[$pks] = $newobj;

      // Now we must process the subobjects.  If the new object wasn't
      // unique, then we forget about it from now on... it'll get
      // overwritten on the next row, or it will go out-of-scope when this function exists.
      $results[$pks]->consume_row_links($defn, $row, $pointer, 0);

      // If the object has a special post-processing method for SELECTs,
      // then run it.
      if(method_exists($results[$pks], 'post_select'))
        $results[$pks]->post_select();

      // Mark the result as unmodified
      $results[$pks]->modified = false;
      $results[$pks]->brandnew = false;
    }

    // Return the result array.
    return $results;
  }



  /**
   * Deletes an object by primary key.
   *
   * This is a simple deletion function using DELETE FROM on the object by
   * primary key.  It does not cascade through links, and it doesn't
   * handle transactions in any special way.  However it does call {@link
   * pre_delete()} if defined.
   *
   * @todo This function is a hack and should be done a lot better.  Model
   * objects should also implement their deletions correctly by overriding
   * this method.
   */
  public function delete() {
    $defn = $this->defn();
    if(method_exists($this, 'pre_delete'))
      $this->pre_delete();

    $wheres = $defn->where_clauses($defn->tablename);
    $sql = 'DELETE FROM '.$defn->tablename.' WHERE '.$wheres;
    $dbh = DatabaseHandle::get_instance();
    $pkv = $this->primary_key_values();
    $dbh->query_with_exceptions($sql, $pkv);
  }



  /**
   * Saves the current object to the database using UPDATE, INSERT or REPLACE.
   *
   * This function will perform an SQL UPDATE, INSERT or REPLACE on an
   * object.  Unless $force_mode is set to one of UPDATE, INSERT or
   * REPLACE, it will base the decision of which operation to use based
   * on the {@link $modified} and {@link $brandnew} flags.  The SQL operation is
   * performed within a transaction, and will rollback and throw an
   * Exception if anything weird happens.
   *
   * If {@link DatabaseObjectDefinition::$memcache_timeout} is not false,
   * $memcache_timeout is not false and there is a working (and enabled)
   * memcache object, the cache will be updated accordingly.
   */
  public function save($recurse=false, $force_mode=null, $memcache_timeout=false) {

    // Get the database handle, and set autoCommit as false, thereby
    // implicitly starting a transaction.
    $dbh = DatabaseHandle::get_instance();
    $dbh->autoCommit(false);

    // The object's DatabaseObjectDefinition is needed to construct the
    // query.
    $defn = $this->defn();

    // Updates may throw Exceptions if there was a faulty or suspicious
    // UPDATE, so we need to catch these, and rethrow them.
    try {

      switch ($force_mode) {
      case 'UPDATE':
        // The caller has requested an UPDATE, so give it a go.  If
        // ->modified is empty or not set, the UPDATE will silently exit.
        $this->_update($defn, $dbh, $recurse);
        break;

      case 'INSERT':
        // The caller has requested an INSERT, so try it.  If there is
        // already a row there, it will result in an Exception and
        // rollback.
        $this->_insertreplace($defn, $dbh, $recurse, false, true);
        break;

      case 'REPLACE':
        // The caller has requested a REPLACE, so try it.
        $this->_insertreplace($defn, $dbh, $recurse, true, true);
        break;

      default:
        // There was no specified mode, so try to deduce the correct one
        // using the ->modified and ->brandnew flags.
        if($this->brandnew) {
          // Perform a REPLACE
          $this->_insertreplace($defn, $dbh, $recurse, true, true);

        } else if($this->modified and count($this->modified)>0) {
          // Perform an UPDATE
          $this->_update($defn, $dbh, $recurse);

        } else {
          // Nothing to do!
        }
        break;
      }

    } catch (Exception $e) {
      // There was a failure, so rollback and rethrow the Exception.
      $dbh->rollback();
      throw $e;
    }

    // Everything should be okay, so commit.
    $r = $dbh->commit();

    // If there was an error, then we need to throw an exception.
    if(!$r)
      throw new QueryErrorException($dbh->errorMessage());

    // Add it to memcache if requested
    if(defined('MEMCACHE_USE') and MEMCACHE_USE) {

      // If there's a memcache...
      global $MEMCACHE;
      if($MEMCACHE) {

        // If there wasn't an explicitly stated timeout, then use the
        // object's default.
        if(!$memcache_timeout and isset($defn->memcache_timeout))
          $memcache_timeout = $defn->memcache_timeout;

        // Whether stated or implicit from the object definition, if
        // there's a timeout, then add to the cache.
        if($memcache_timeout)
          $MEMCACHE->set($memcache_obj_key, $this, false, $memcache_timeout);
      }
    }
  }



  /**
   * Performs the UPDATE but not the transaction work.
   *
   * This function is for internal use by {@link save()} only.
   *
   * @todo This function needs to handle links: either by cascading, or at
   * least synchronising the primary key of the linked object.
   * @internal
   * @ignore
   */
  protected function _update(DatabaseObjectDefinition $defn, $dbh, $recurse=false) {

    // If no columns were modified then don't bother with the UPDATE.
    if(!is_array($this->modified) or !count($this->modified))
      return false;

    // If the object has a special pre-processing method for UPDATEs, then
    // run it.
    if(method_exists($this, 'pre_update'))
      $this->pre_update();

    // We build a "column=value" pair for each modified column,
    $assignments = array();

    // Each entry in $this->modified is a column that will need updating
    // in the row.
    foreach ($this->modified as $key=>$val) {

      // If the entry is marked true (almost always so), then this column
      // has been modified.
      if($val) {

        // We handle links and columns completely differently, so check
        // the definition to see if it is a link.
        if(isset($defn->links[$key])) {
          // It's a link. XXX: Unfortunately, I haven't done this bit yet,
          // so for the time being, we'll ignore it.
          if($recurse)
            // Hmm.. what to do re: INSERT/UPDATE dichotomy?
            // $this->_data[$key]->($dbh, $recurse);
            throw new Exception("Cannot update link $key: unimplemented");

        } else {
          // It's a normal column, so we need to add a bit of syntax
          // (columnname=?) and push the key to the placeholder stack.
          if(isset($defn->columns[$key])) {
            // If the key is set in the normal column array, then we can
            // happily just add it.
            $assignments[] = $defn->classname.'.'.$key.'=?';
            $vals[] = $this->_data[$key];
          } else {
            // Otherwise, we need to look through the definition to see if
            // it's an alias.  Since the definition mapping maps the
            // database-side name to the PHP-side name, we have to loop to
            // find the database-side name from the PHP-side name ($key)
            foreach ($defn->columns as $col=>$aliasortrue) {
              if($aliasortrue == $key) {
                // The real database-side name is found, so add it to the
                // SQL and the placeholder stack.
                $assignments[] = $defn->classname.'.'.$col.'=?';
                $vals[] = $this->_data[$key];
                break; // Jump out of this loop, as we've just found it.
              }
            }
          }
        }
      }
    }

    // Build the SQL, using the assignments set above.  Use the normal
    // DatabaseObjectDefinition::where_clauses to identify the object.
    $sql = array();
    $sql[] = 'UPDATE '.$defn->tablename.' '.$defn->classname;
    $sql[] = 'SET '.join(', ', $assignments);
    $sql[] = 'WHERE '.$defn->where_clauses($defn->classname);

    // Join up the SQL into a string.
    $sql = join(' ', $sql);

    // Add the primary key values to the end of the placeholder stack to
    // be passed to ::query_with_exceptions
    $vals = array_merge($vals, $this->primary_key_values());

    // Execute the UPDATE using the values set above.
    $q = $dbh->query_with_exceptions($sql, $vals);

    // We need to check that we've only updated one row.
    $rows = $dbh->affectedRows();
    if($rows > 1)
      throw new ModelIncompatibilityException('More than one row was affected in UPDATE of '.$defn->classname);

    // If the object has a special post-processing method for UPDATEs,
    // then run it.
    if(method_exists($this, 'post_update'))
      $this->post_update($q);

    // At this point, we have updated either one row or no rows, so the
    // object is *probably* committed to the database.  XXX: I *think*
    // affectedRows() returns the number of rows actually changed rather
    // than matched, so if the data hasn't actually changed, this will
    // return false.  I'm not sure this is right.
    $this->modified = false;
    $this->brandnew = false;

    if($rows == 1) {
      // The data has been committed successfully to one row, so we should
      // be okay.
      return true;
    } else {
      // This is the weird case: is it normal that this will occur even if
      // the row matched?  I think so.
      return false;
    }
  }



  /**
   * Performs either an INSERT or a REPLACE but not the transaction work.
   *
   * This function is for internal use by {@link save()} only.
   *
   * @internal
   * @ignore
   */
  protected function _insertreplace(DatabaseObjectDefinition $defn, $dbh, $recurse=false, $replace=true, $set_autoincr=true) {

# NOW USING AUTO_INCREMENT IN MYSQL INSTEAD.  Gid, 31/Oct/2005.
#   // Firstly, we need to perform an autoincrement if requested and
#   // necessary.
#   if($set_autoincr)
#
#     // An autoincrement was specified in the function call
#     if(isset($defn->autoincrement)) {
#
#       // An autoincrement specification exists for this object, so set
#       // it, using a sequence courtesy of PEAR::DB::nextId
#       $this->_data[$defn->autoincrement] =
#         $dbh->nextId($defn->tablename.'__'.$defn->autoincrement);
#
#       // ...and mark it as modified.
#       $this->mark_modified($defn->autoincrement);
#     }

    // If the object has a special pre-processing method for INSERTs and
    // REPLACEs, then run it.
    if(method_exists($this, 'pre_insert'))
      $this->pre_insert();

    // Read the primary keys for this object.  We need to make sure the
    // primary keys exist for it, otherwise the insert will fail.
    $pkvs = $this->primary_key_values();

    // If either the primary key is not true (since we specify that
    // primary keys cannot be zero), or if it's an aggregate then there is
    // one or more non-null primary keys for the object, then we must
    // fail.
    if(!$pkvs or !count($pkvs))
      throw new Exception("Cannot insert a null object");

    // We are using the "INSERT INTO foo SET col1=?, col2=?" rather than
    // the more normal "INSERT INTO foo (col1,col2) VALUES (?,?)", as it's
    // easier to deal with.  Saying that, it would be straightforward to
    // change.
    $assignments = array();
    $vals = array();

    // For each column in the object definition...
    foreach ($defn->columns as $col=>$aliasortrue) {
      if($aliasortrue===true) {
        // The column is not aliased, so just push the assignment and the
        // value for the assignment to the appropriate stacks.
        if(array_key_exists($col, $this->_data)) {
          $assignments[] = $col.'=?';
          $vals[] = $this->_data[$col];
        }
      } else {
        // The column is aliased, so we need to pass the alias instead.
        // No biggie.
        if(array_key_exists($aliasortrue, $this->_data)) {
          $assignments[] = $col.'=?';
          $vals[] = $this->_data[$aliasortrue];
        }
      }
    }

    // Now we must build the SQL for the insert.

    $sql = array();

    // REPLACE is a MySQL-ism, which will overwrite any existing data with
    // the same primary key.  Otherwise the syntax is identical, so here
    // we choose which to use based on the function parameter.
    if($replace)
      $sql[] = 'REPLACE';
    else
      $sql[] = 'INSERT';

    // More SQL...
    $sql[] = 'INTO '.$defn->tablename;

    // Add the placeholder assignments from the loop above.
    $sql[] = 'SET '.join(', ', $assignments);

    // Stringify the SQL
    $sql = join(' ', $sql);

    // Perform the SQL, passing the value stack to fill in the
    // placeholders.
    $q = $dbh->query_with_exceptions($sql, $vals);

    // If there's an autoincrement field, then we'll need to retrieve it
    // if that column was not specified.
    if($autoIncrColumn = $defn->autoincrement) {
      if(!array_key_exists($autoIncrColumn, $this->_data) or !$this->_data[$autoIncrColumn])
        $this->_data[$autoIncrColumn] = $dbh->lastInsertId();
    }


    // If the object has a special post-processing method for INSERTs,
    // then run it.
    if(method_exists($this, 'post_insert'))
      $this->post_insert($q);

    // Mark it as unmodified.
    $this->brandnew = false;
    $this->modified = false;

    // The query would fail with an exception if the insert was
    // unsuccessful, so we can just assume it worked.
    return true;
  }



  /**
   * Marks a given key as modified
   *
   * For the purposes of {@link update()} and {@link replace()}
   */
  public function mark_modified($key) {
    // This function marks a given key as modified, for the purposes of
    // the update() and replace() methods.
    if(!is_array($this->modified))
      $this->modified = array();

    if(is_array($key))
      foreach($key as $k)
        $this->modified[$k] = true;
    else
      $this->modified[$key] = true;
  }



  /**
   * Returns an iterator for the case when this object is being
   * treated as an ArrayAggregate.
   *
   * @return Iterator
   */
  public function getIterator() {
    return new ArrayIterator($this->_data);
  }



  /**
   * Substitute for array_keys() when this object is being used as an
   * ArrayAggregate.
   *
   * @return array
   */
  public function keys() {
    // XXX: Why doesn't ArrayAccess allow array_keys? Bad design.
    return array_keys($this->_data);
  }



  /**
   * Sets a key to a given value.
   *
   * Used as an overload of the subscript operator [], and as an accessor
   * writing method, this method is used to set data in the object: both
   * atomic and links. It will mark the key as modified in the object for
   * a later {@link save()}.  The method can throw a range of Exceptions,
   * usually as a result of bogus input, mismatching the data model.
   *
   * Examples:  (these two are equivalent)
   * <pre>
   *     $user->offsetSet('name', 'Tom Gidden');
   *     $user['name'] = 'Tom Gidden';
   * </pre>
   *
   * Note: due to limitations in PHP at the time of writing, the subscript
   * overload cannot be used on the $this object inside a method.  As a
   * result, the longer form must be used.
   */
  public function offsetSet($key, $nval, $force=false) {
    // XXX: This is not the best test, but it's fast: we shouldn't have to
    // look up the definition every time.

    if(is_scalar($nval) or $force) {
      // Simple variable assignment: set the value and mark it as modified
      $this->_data[$key] = $nval;
      $this->mark_modified($key);

    } else if(is_object($nval) and $nval instanceof DatabaseObject) {
      // Object assignment: not only do we set the object in _data, but we
      // also set the foreign keys.  To do this, we must look at the
      // object definition.
      $defn = $this->defn();

      // Check to see that there is a link for this object.  If not, then
      // the programmer is trying to put a non-link object into the _data
      // array: BAD!
      if(!isset($defn->links) or !array_key_exists($key, $defn->links))
        throw new Exception("Cannot set object as non-link data");

      // Look up the foreign key(s) in the new object and set them in the
      // parent object ($this).

      if(is_string($fks = $defn->links[$key]['foreign_key'])) {
        // The foreign key is a simple string, so we can just grab one value.

        // If the foreign key is null, then we have a problem.  XXX: I'm
        // not sure this is the right thing to do.
        if(is_null($nval[$fks]))
          throw new Exception("Foreign key reference $fks is null in value given for $key");

        // Set the data for the foreign key in the parent, and mark as
        // modified.  We will insert the object later.
        $this->_data[$fks] = $nval[$fks];
        $this->mark_modified($fks);

      } else if(is_array($fks)) {
        // The foreign key is aggregated, so we need to loop through all
        // parts.
        foreach ($fks as $fk=>$ref) {
          // If the foreign key is null, then we have a problem.  XXX: I'm
          // not sure this is the right thing to do.
          if(is_null($nval[$ref]))
            throw new Exception("Foreign key reference $ref is null in value given for $key");

          // If the array index is a numeric, then we've got a
          // non-associative array, so use $ref as the index.  XXX: We
          // really should check if $fk/$ref is a valid foreign key.
          // We've already checked if $ref is a valid foreign key
          // reference in the other object, but not in *this* object.
          if(is_numeric($fk)) {
            // Set the data for the foreign key in the parent, and mark as
            // modified.  We will insert the object later.
            $this->_data[$ref] = $nval[$ref];
            $this->mark_modified($ref);
          } else {
            // Set the data for the foreign key in the parent, and mark as
            // modified.  We will insert the object later.
            $this->_data[$fk] = $nval[$ref];
            $this->mark_modified($fk);
          }
        }
      } else
        // The foreign key definition is faulty.
        throw new Exception("Foreign key specification for link $key is faulty");

      // Set the object itself into $this.
      $this->_data[$key] = $nval;

    } else if(is_array($nval) or $nval instanceof DatabaseArray) {
      // Array assignment: this is usual for a one-to-many link.

      // Load the object definition so we can check if the link is
      // defined.
      $defn = $this->defn();

      // If the specified key is not a known link, then something is
      // wrong.
      if(!isset($defn->links) or !array_key_exists($key, $defn->links))
        throw new Exception("No such link exists on object.");

      // If an array is assigned to a one-to-one link, we also have a
      // problem.
      if(!$defn->links[$key]['one-to-many'])
        throw new Exception("Arrays cannot be assigned to one-to-one links.");

      // Set the link in the object, as requested
      $this->_data[$key] = $nval;

      // ...and mark it.
      $this->mark_modified($key);

      // XXX: Note, it is still the programmer's responsibility to set
      // each entry in the DatabaseArray to have this object as its
      // parent.  It is not automatic.

    } else if(is_null($nval)) {

      // A null is set, so put it in as normal.
      $this->_data[$key] = null;

      // ...and mark it
      $this->mark_modified($key);

    } else
      // The incoming value is neither a DatabaseObject nor a scalar, so
      // we need to fail here.  Note, there is a reasonably good case for
      // allowing arrays here, but it's a whole new world of trouble.
      throw new Exception("Bad value for $key");
  }



  /**
   * Unsets a key in the object.
   *
   * Used as an overload of the subscript operator [], this method is used
   * to unset data in the object: both atomic and links. It will mark the
   * key as modified in the object for a later {@link save()}.
   *
   * XXX: This should do a whole bunch of things, including updating of
   * links, etc.
   *
   * Examples:  (these two are equivalent)
   * <pre>
   *     $user->offsetUnset('name');
   *     unset($user['name']);
   * </pre>
   *
   * Note: due to limitations in PHP at the time of writing, the subscript
   * overload cannot be used on the $this object inside a method.  As a
   * result, the longer form must be used.
   */
  public function offsetUnset($key) {
    // XXX: This should do a whole bunch of things, including updating of
    // links, etc.
    if(array_key_exists($key, $this->_data)) {
      unset($this->_data[$key]);
      $this->mark_modified($key);
    }
  }



  /**
   * Return whether the key exists in the object.
   *
   * Needed for implementation of ArrayAggregate.
   *
   * @return boolean
   */
  public function offsetExists($key) {
    // Return whether the key exists.  Needed for ArrayAggregate.
    return array_key_exists($key, $this->_data);
  }


  /**
   * Get the value of a key in the object.
   *
   * Used as an overload of the subscript operator [], and as an accessor
   * reading method, this method is used to get data from the object: both
   * atomic and links.
   *
   * The offsetGet function is used to retrieve the "properties" of the
   * object (which aren't really properties, as all of the data is stored
   * in an array {@link $_data} rather than as part of the object.
   *
   * If the key does not exist but is still part of the definition (as a
   * deferred link), then it needs to be retrieved.
   *
   * Examples:  (these two are equivalent)
   * <pre>
   *     print $user->offsetGet('name');
   *     print $user['name'];
   * </pre>
   *
   * Note: due to limitations in PHP at the time of writing, the subscript
   * overload cannot be used on the $this object inside a method.  As a
   * result, the longer form must be used.
   */
  public function offsetGet($key) {
    // If the key exists in the data, then just return it.
    if(array_key_exists($key, $this->_data))
      return $this->_data[$key];

    // Otherwise, we need to check the object definition to see if it's a
    // link or a column (or a mistake)
    $defn = $this->defn();

    // If the column does exist in the definition, but not in the data,
    // then this is probably a new object, rather than a retrieved
    // object.
    if(isset($defn->columns[$key])) {
      if($this->brandnew)
#       // The object is new, so it's safe to return null
        return null;
      else
#       // The object is not new, so it should have the data.  Since it
#       // doesn't, there's probably a mistake.
        throw new Exception("Column '$key' is not set on object.");
    }
    else if(!isset($defn->links) or !array_key_exists($key, $defn->links))
      // If there are no links defined or no such link exists, there's
      // absolutely no point in trying.  At this point, the programmer
      // is probably using a key that isn't part of the object's schema,
      // implying an error.
      throw new Exception("Link '$key' does not exist in object definition for '".$defn->classname."'");

    // This link DOES exist, but the data's not there: hmmm.. must be a
    // deferred link, so go get it.
    return $this->get_link($key, $defn);
  }

  /**
   * Check to see if a property can be set for this object
   */
  public function canSet($key) {
    // If the key exists in the data, then just return it.
    if(array_key_exists($key, $this->_data))
      return true;

    $defn = $this->defn();
    if(isset($defn->columns[$key]))
      return true;
    else if(!isset($defn->links) or !array_key_exists($key, $defn->links))
      return false;
    return true;
  }

  /**
   * (Retrieves and) returns the data for a deferred link.
   *
   * Used by {@link offsetGet}, there is little reason to use this method
   * directly.  However, it needs to be public for friend access from
   * other objects.
   *
   * This function will retrieve the data for a deferred link, and return
   * it.  Given the link name, it should return either an object (if it's
   * a 1-1 link), or an array of objects (if it's a 1-n link).
   *
   * @ignore
   * @internal
   * @return mixed
   */
  public function &get_link($linkname, $defn=null) {
    // If no definition was passed to this routine, then retrieve it for
    // this object.
    if(is_null($defn))
      $defn = $this->defn();

    // The link array contains the information to construct the query AND
    // to parse the results.
    $linkarr = $defn->links[$linkname];

    // Since $_queries is static across all DatabaseObjects, it's worth
    // caching this query: if other objects of this type (when subclassed)
    // have deferred links, then these queries can be used for them too.
    $query_name = get_class($this).'__'.$linkname;

    // Is the query already cached?
    if(isset(self::$_queries[$query_name])) {

      // Yes, it's cached, so just retrieve it from the SQL cache.
      $sql = self::$_queries[$query_name];

    } else {
      // No, it's not cached, so we need to build the SQL query.  In this
      // case, we use $defn->clauses().  By setting $query_link to
      // $linkname, the return results will be based on the base object
      // and the subobject, but the query will only return data from the
      // subobject.  As a result, we can then parse the results using the
      // subobject's definition rather than the base object.  Nifty, and
      // it means we don't need any special case for forcing a previously
      // deferred link.
      list($cols, $from, $where) = $defn->clauses(null, $linkname);

      // The SQL is simple, although this will not handle DISTINCT, GROUP
      // BY or anything like that.  Sorry!
      $sql = 'SELECT '.$cols.' FROM '.$from.' WHERE '.$where;

      // Cache the query for later.  $sql is now the SQL we want to run.
      self::$_queries[$query_name] = $sql;
    }

    // To retrieve the objects, we need to know the primary keys of the
    // base object so we can retrieve the subobject.  These keys form the
    // WHERE clause of the query.
    $pkvs = $this->primary_key_values();

    // Execute the query, using the SQL and the primary keys as above.
    $q = DatabaseHandle::get_instance()->query_with_exceptions($sql, $pkvs);

    // PHP has issues with "$foo = new ($linkarr['classname'])();" and we
    // have to use the classname once or twice anyway.
    $linkclass = $linkarr['classname'];

    // Get the definition of the new object.  If it fails, we can't do
    // much more.
    if(!($linkdefn = DatabaseObjectDefinition::get_instance($linkclass)))
      throw new Exception("Cannot find object definition for $linkclass");

    // For each row, representing the sub-object(s)
    while($row = $q->fetch(PDO::FETCH_NUM)) {

      // Even though the query was built using clauses() on the base
      // object's definition, we parse the results using the subobject's
      // definition instead.  This is because the clauses() call
      // constructed a query that doesn't contain the base object data.

      // Create a new object (of the type of the subobject, NOT the base
      // object), although it might be discarded if it turns out to be a
      // duplicate (due to one-to-many links)
      $newobj = new $linkclass();

      // $pointer indicates the position in the row of the parser.  This
      // algorithm walks from left-to-right along the row, without
      // backtracking.
      $pointer = 0;

      // Copy all relevant columns to the new object.
      $newobj->consume_row_columns($linkdefn, $row, $pointer);

      // We need to detect if the object is a duplicate and choose the
      // object to recurse on as a result.  Even if it's a duplicate, the
      // rest of the row must be correctly skipped (on $pointer), as it
      // may (will probably) contain unique objects further across.

      // Retrieve its primary keys to check for duplicates.
      $pk = $newobj->serialised_primary_keys();

      // XXX: It may be better if the new object creation was deferred
      // until after a primary key check.  The primary keys would be
      // looked up in the array without the other columns, and then tested
      // for duplicates.  If none, _THEN_ the object would be
      // created. Downside: the primary keys need extracting from the
      // ordered array, involving another pass through the definition.
      // After that, $pointer will still need to be increased.  As an
      // alternative, consume_row_columns could be wired to abort early if
      // different primary keys are found.  While this would work, it
      // makes the code messy.

      // If the primary key(s) are null, then this object should be
      // skipped.
      if(is_null($pk)) {

        // If the link was not listed as nullable, then we have a problem.
        if(!isset($linkarr['nullable']) or !$linkarr['nullable'])
          throw new Exception("Null data found on non-nullable link");

        // We now need to skip the NULL entries.
        $linkdefn->skip_row_children($pointer, $cur_level, $link_path, null);

      } else {
        // One-to-many links result in an array of objects being stored
        // as the link, rather than a single object.  As a result, the
        // two scenarios have to be handled differently.

        if(isset($linkarr['one_to_many']) and $linkarr['one_to_many']) {
          // One-to-many links: needs to check if an object with the
          // same primary key exists in the link already.

          // Check if the destination array already exists.  If it doesn't
          // initialise it as an empty array.
          if(!isset($this->_data[$linkname])) {
            // Initialise it
            $this->_data[$linkname] = array(); // XXX: was  "= new DatabaseArray();"

            // $destarr is the array acting as the link in the object being
            // worked on.  This is the destination for our new object.
            $destarr = &$this->_data[$linkname];
          }

          // Check if the object already exists in the array.  If it does,
          // then this should mainly be to do with sub-objects.
          if(!isset($destarr[$pk]))
            // It doesn't exist, so we're going to want to use $newobj after
            // all, so set it in the destination array.  XXX: why doesn't
            // '&' help here?  Ho hum.
            $destarr[$pk] = $newobj;

          // Now that we've inserted the subobject into the base object, we
          // need to process the subobject's own links.
          $destarr[$pk]->consume_row_links($linkdefn, $row, $pointer, 0, null, get_class($this).'_'.$linkname);

          // Mark it as unmodified
          $destarr[$pk]->modified = false;
          $destarr[$pk]->brandnew = false;

        } else {
          // One-to-one links... much easier.

          // Does the object exist in the base object?
          if(!isset($this->_data[$linkname]))
            // No, so put the new object there.  We're going to use $newobj
            // after all.
            $this->_data[$linkname] = $newobj;
          else
            // Yes, it does exist, but is it the same object? (ie. same primary keys)
            if($pk != $this->_data[$linkname]->serialised_primary_keys($linkdefn))
              // No.  This means that two objects have arrived for a
              // one-to-one link.  This is probably a difference between the
              // database schema and the object definition.
              throw new ModelIncompatibilityException("Allegedly one-to-one link '$linkname' is actually one-to-many");

          // Now that we've inserted the subobject into the base object, we
          // need to process the subobject's own links.
          $this->_data[$linkname]->consume_row_links($linkdefn, $row, $pointer, 0, null, get_class($this).'_'.$linkname);

          // Mark it as unmodified
          $this->_data[$linkname]->modified = false;
          $this->_data[$linkname]->brandnew = false;
        }
      }
    }

    // The data should have been retrieved by now, so return it.
    return $this->_data[$linkname];
  }



  /**
   * Returns the {@link DatabaseObjectDefinition} for an object.
   *
   * This is a singleton.  This is cached by {@link get_instance()}, but
   * it still isn't a bad idea for it to be cached.
   *
   * @internal
   * @return DatabaseObjectDefinition
   */
  protected function defn() {
    return
      DatabaseObjectDefinition::get_instance(get_class($this));
  }



  /**
   * Uses the supplied definition to extract the appropriate columns
   * from the row into the current object, and advance the pointer.
   *
   * @internal
   * @ignore
   */
  private function consume_row_columns(&$defn, &$row, &$pointer) {
    // The definition should be passed to this object, but if it isn't
    // (ie. lazy programmer), then get it.  It's less efficient, so it
    // should be included.
    if(is_null($defn)) $defn = $this->defn();

    // For each column in the definition...
    foreach ($defn->columns as $dbcolumn=>$aliasortrue) {
      // Decide whether it's an alias or not
      if($aliasortrue === true)
        // It's true, so just copy from the row with the same name.
        $this->_data[$dbcolumn] = $row[$pointer++];
      else
        // It's an alias, so copy from the row, but with a new name.
        $this->_data[$aliasortrue] = $row[$pointer++];
    }
  }



  /**
   * Uses the supplied definition to traverse the links for the current
   * object, calling consume_row_columns() where necessary.
   *
   * $cur_level and $table_path are used to limit the traversal.
   *
   * @internal
   * @ignore
   */
  private function consume_row_links(&$defn,
                                     &$row,
                                     &$pointer,
                                     $cur_level = 0,
                                     $limit_overrides = null,
                                     $table_path = null) {
    // The definition should be passed to this object, but if it isn't
    // (ie. lazy programmer), then get it.  It's less efficient, so it
    // should be included.
    if(is_null($defn)) $defn = $this->defn();

    // No point in running if there aren't any links!
    if(!isset($defn->links)) return;

    // If the table path is null, then this is a new traversal, so start
    // with the class name as the path for the base table columns.
    if(is_null($table_path))
      $table_path = $defn->classname;

    // For each link...
    foreach ($defn->links as $linkname=>$linkarr) {

      // The link path is the table alias in the query.  For example, if
      // based on the object 'User' (represented as the 'user' table in
      // the DB), the link 'organisation' will mean that the 'org' table
      // is aliased to 'User_org'.  This notation is used for limit paths
      // as well.
      $link_path = $table_path.'_'.$linkname;

      // Since we don't always want to traverse (an infinitely deep tree,
      // sometimes), we need to run decide_limits() to give a yes/no
      // answer on whether to traverse.  These same rules are used when
      // constructing the query, so the row stays in sync.
      if(!$defn->decide_limits($linkarr, $link_path, $cur_level, $limit_overrides)) continue;

      // The definition object for the link is necessary to work out how
      // to handle the link.
      $linkdefn = DatabaseObjectDefinition::get_instance($linkarr['classname']);

      // Check to see if the foreign key(s) is null
      $foreign_key_is_null = false;

      if(is_string($linkarr['foreign_key'])) {
        // It's only one column, so the check is easy
        if(is_null($this->_data[$linkarr['foreign_key']]))
          $foreign_key_is_null = true;

      } else if(is_array($linkarr['foreign_key'])) {
        // It's an array, so we need to check each key part in turn
        foreach ($linkarr['foreign_key'] as $fk=>$ref) {
          if(is_numeric($fk)) {
            // The foreign key array is ordered rather than associative,
            // so use the $ref as the key name.
            if(is_null($this->_data[$ref]))
              $foreign_key_is_null = true;
          } else {
            // The foreign key array is a simple mapping (at this point).
            if(is_null($this->_data[$fk]))
              $foreign_key_is_null = true;
          }
        }
      }

      // If the foreign key is NULL, then there's no point in constructing
      // the tree.
      if($foreign_key_is_null) {
        // It's NULL, but the row pointer still needs to be advanced to
        // skip over the NULL entries.
        $linkdefn->skip_row_object_and_children($pointer, $cur_level, $link_path, $limit_overrides);
      } else {
        // It's not NULL, so start building the tree.  First, create an
        // object.  This may be disposed of later, if this object has
        // already been done.
        $newobj = new $linkarr['classname'];

        // Read the column data for the object, using the object's
        // definition as a guide.
        $newobj->consume_row_columns($linkdefn, $row, $pointer);

        // Get the primary keys (as a comparable scalar) to check if it's
        // already there.
        $pk = $newobj->serialised_primary_keys($linkdefn);

        // If the primary key is null, then this was (almost certainly) a
        // LEFT OUTER JOIN, so we need to skip the NULL entries.
        if(is_null($pk)) {

          // If the link was not listed as nullable, then we have a problem.
          if(!isset($linkarr['nullable']) or !$linkarr['nullable'])
            throw new Exception("Null data found on non-nullable link '$link_path'");

          // We now need to skip the NULL entries.
          $linkdefn->skip_row_children($pointer, $cur_level, $link_path, $limit_overrides);

        } else {

          // The link is handled differently for 1-n links than 1-1.
          if(isset($linkarr['one_to_many']) and $linkarr['one_to_many']) {

            // One-to-many links are written to the object as arrays.  If
            // the array doesn't exist yet, then this is the first row for
            // this part of the tree, so initialise the array.
            if(!isset($this->_data[$linkname]))
              $this->_data[$linkname] = array(); // XXX: was "new DatabaseArray();"

            // If this object (identified by the primary keys) doesn't
            // exist, then put the new object into the array (before we go
            // on to traverse it).
            if(!isset($this->_data[$linkname][$pk]))
              $this->_data[$linkname][$pk] = $newobj;

            // The new object is complete and inserted into the parent
            // object, so now we need to recurse into the new object's links
            // (ie. the sub-sub-objects).  We don't pass on the $query_link,
            // as that only happens for the first level (sorry!)
            $this->_data[$linkname][$pk]->consume_row_links($linkdefn, $row, $pointer, $cur_level+1, $limit_overrides, $link_path);

            // If the object has a special post-processing method, then run it.
            if(method_exists($this->_data[$linkname][$pk], 'post_select'))
              $this->_data[$linkname][$pk]->post_select();

            // Mark it as unmodified
            $this->_data[$linkname][$pk]->modified = false;
            $this->_data[$linkname][$pk]->brandnew = false;

          } else {
            // One-to-one links are slightly easier.  They are just put into
            // the object, rather than being wrappered in arrays.

            // If the object doesn't exist in the parent object, then put it
            // there.
            if(!isset($this->_data[$linkname]))
              $this->_data[$linkname] = $newobj;

            // Otherwise, the primary key of the new object should match the
            // existing one.  If not, then we've got two different objects
            // on a 1-1 link, which implies that the definition of the
            // object (specifically the primary key specification) is
            // out-of-sync with the database schema.
            else if($pk != $this->_data[$linkname]->serialised_primary_keys($linkdefn)) {
              throw new ModelIncompatibilityException("Allegedly one-to-one link '$linkname' is actually one-to-many");
            }

            // The new object is complete and inserted into the parent
            // object, so now we need to recurse into the new object's links
            // (ie. the sub-sub-objects).  We don't pass on the $query_link,
            // as that only happens for the first level (sorry!)
            $this->_data[$linkname]->consume_row_links($linkdefn, $row, $pointer, $cur_level+1, $limit_overrides, $link_path);

            // If the object has a special post-processing method, then run it.
            if(method_exists($this->_data[$linkname], 'post_process'))
              $this->_data[$linkname]->post_process();

            // Mark it as unmodified
            $this->_data[$linkname]->modified = false;
            $this->_data[$linkname]->brandnew = false;
          }
        }
      }
    }
  }



  /**
   * Returns a serialised version of the primary keys for this object,
   * suitable for use as a key in an associative array.
   *
   * Note, this routine is pretty dumb, without much type-checking.
   *
   * The definition should be passed to this object, but if it isn't
   * (ie. lazy programmer), then it will retrieve it.  It's less
   * efficient, so it should be included.
   *
   * @return string
   */
  protected function serialised_primary_keys(&$defn=null) {
    // The definition should be passed to this object, but if it isn't
    // (ie. lazy programmer), then get it.  It's less efficient, so it
    // should be included.
    if(is_null($defn)) $defn = $this->defn();

    // The key column(s) can be a string (one column primary key) or an
    // array (aggregate primary key)
    $keycol = &$defn->idcolumns;

    if(is_array($keycol)) {
      // The primary key definition is an aggregate key (multiple
      // columns), so serialise and return the contents of those columns.
      $buf = array();

      // We need to check for when all keys are null.
      $is_all_null = true;

      // Loop through the parts of the primary key, adding them to the
      // array.
      foreach ($keycol as $col) {
        if(!is_null($val = $this->_data[$col]))
          $is_all_null = false;
        $buf[] = $val;
      }

      // If all primary keys are null, then 'null' should be returned
      // rather than an array of nulls.
      if($is_all_null) return null;

      // Punt out the keys as a nice scalar.
      return join(',',$buf);

    } else {
      // It's probably a scalar.  If it's not, then that's bad!  However,
      // since this routine is executed a lot in traversal, it's not worth
      // doing tests.
      return $this->_data[$keycol];
    }
  }



  /**
   * Returns an array of the values of the primary keys for this object.
   *
   * Note, this routine is pretty dumb, without much type-checking.
   *
   * The definition should be passed to this object, but if it isn't
   * (ie. lazy programmer), then it will retrieve it.  It's less
   * efficient, so it should be included.
   *
   * @return array
   */
  protected function primary_key_values(&$defn=null) {
    if(is_null($defn)) $defn = $this->defn();

    // $keycol contains the names of the primary key(s)
    $keycol = &$defn->idcolumns;

    if(is_array($keycol)) {
      // The primary key is aggregated as an array (note, it could still
      // only have one member, although that would be less efficient.
      $buf = array();

      // For each column in the key...
      foreach ($keycol as $col)
        // Add that key's data to the buffer.
        $buf[] = &$this->_data[$col];

      // Return that array, thus containing the values of the primary
      // keys.
      return $buf;

    } else {
      // It's probably a scalar.  If it's not, then that's bad!  However,
      // since this routine is executed a lot in traversal, it's not worth
      // doing tests.
      return array(&$this->_data[$keycol]);
    }
  }



  /**
   * Returns a serialisation of the object in JSON (Javascript Object Notation).
   *
   * Dumps the object as a Javascript string.  This could (should?) be
   * overridden in the subclass.  The second parameter, $template, allows
   * transformation and filtering of the keys.
   *
   * Template example:
   * <pre>
   *  $template = array('column1'=>true,
   *                    'column2'=>'c2',
   *                    'link1'=>array(ALIAS=>'l1',
   *                                   'subcolumn1'=>'sc1',
   *                                   'subcolumn2'=>true))
   * </pre>
   *
   * This would dump an object containing column1, c2 (an alias for
   * column2) and l1 (an alias for link1) containing 'sc1' and
   * 'subcolumn2'.  The ALIAS pair is optional, but allows links to be
   * aliased as well... yes, it's a hack.  If the ALIAS is set to true,
   * the entire link is "flattened": the link array/object will not be
   * created, and the specified members will be added to the parent
   * object instead.
   *
   * @return string
   */
  public function js($sep="", $template=null) {
    // The Javascript is built in $buf, which is returned as a comma (and
    // $sep) separated list.
    $buf = array();

    // If called with a template, the procedural order is very different.
    if(!is_null($template)) {

      // A template was used, so use it as a structure for serialization:
      // handle each template member in turn.
      foreach ($template as $col=>$aliasorarray) {
        // $col is the property in the object to retrieve.
        // $aliasorarray is EITHER a scalar (the name to use in
        // Javascript, or true for the same as $col), OR it is an array
        // describing a link/subobject.

        // If the template has an 'ALIAS' key at the root, then ignore it
        // and loop to the next key.
        if($col==='ALIAS') continue;

        // If there is no data for this property, then fast-forward to
        // the next property.
        if(!isset($this->_data[$col])) continue;

        // The type of $aliasorarray changes how to handle the property:
        // either a scalar or a recursion.
        if(is_array($aliasorarray)) {
          // Array, so we should recurse into the object using
          // $aliasorarray as a new template.

          // Choose what property name to use in JS.
          if(isset($aliasorarray['ALIAS']))
            $acol = &$aliasorarray['ALIAS']; // Use the alias
          else
            $acol = &$col; // Use the existing property name (link name)

          // If ALIAS is true, then this link should NOT be a subobject.
          // Instead, the link should be "flattened" into the parent
          // object: the link's properties should be added to the parent
          // object by just returning a string of the property, rather
          // than a labelled and wrappered pair.

          if($aliasorarray['ALIAS']===true) {
            // The link should be flattened to the parent.

            if(is_array($this->_data[$col])) {
              // The property is an array, so serialise each member as a
              // k/v pair
              $buf2 = array();
              foreach ($this->_data[$col] as $k=>$v) {
                $v2 = $v->js($sep,$aliasorarray);
                if(!empty($k) and !empty($v2))
                  if(is_numeric($k))
                    $buf2[] = $k.':'.$v2;
                  else
                    $buf2[] = '\''.$k.'\':'.$v2;
              }

              // Add the k/v pairs to the existing buffer without
              // wrappering in a JS array.
              $buf[] = join(','.$sep, $buf2);

            } else {
              // The property is an object (XXX: presumably!), so just
              // serialise it and add it as a string to the parent object
              $v2 = $this->_data[$col]->js($sep,$aliasorarray);
              if(!empty($v2)) $buf[] = $v2;
            }

          } else {
            // Either the link has an ALIAS (not 'true', but a real
            // alias), or it has no ALIAS.  In either case, use $acol as
            // the JS property name, and add a k/v pair to the buffer

            if(is_array($this->_data[$col])) {
              // The property is an array, so serialise each member as a
              // k/v pair.
              $buf2 = array();
              foreach ($this->_data[$col] as $k=>$v) {
                $v2 = $v->js($sep,$aliasorarray);

                if(!empty($k) and !empty($v2))
                  if(is_numeric($k))
                    $buf2[] = $k.':'.$v2;
                  else
                    $buf2[] = '\''.$k.'\':'.$v2;
              }

              // Add the k/v pairs to the existing buffer, wrappering it
              // as a JS array with a key.
              if(!empty($acol))
                $buf[] = $acol.':{'.join(','.$sep, $buf2).'}';

            } else {
              // The property is an object (XXX: presumably!), so just
              // serialise it and add it as a k/v pair to the parent
              // object.
              $v2 = $this->_data[$col]->js($sep,$aliasorarray);
              if(!empty($acol) and !empty($v2)) $buf[] = $acol.':'.$v2;
            }
          }

        } else {
          // The template is just a scalar, so shouldn't recurse.

          // Choose what property name to use in JS.
          if($alias===true)
            $acol = &$col;      // Use the original property name
          else
            $acol = &$aliasorarray; // Use the alias

          // Serialise the property and add it to the main buffer.
          $v2 = $this->js_literal($acol, $this->_data[$col], $sep);
          if(!empty($v2)) $buf[] = $v2;
        }
      }

      // If there is a template, and it has an ALIAS at the root, then
      // this is presumably a recursed call for a flattened serialisation,
      // and the wrapper shouldn't be added (as the parent call will want
      // to output it as-is rather than as a subobject)
      if(is_array($template) and $template['ALIAS']===true)
        // It's a recursed, flattened call, so just output the buffer
        return join(','.$sep, $buf);
      else
        // It's a proper object call, so wrapper it as a JS array.
        return '{'.$sep.join(','.$sep, $buf).$sep.'}';

    } else {

      // No template was given, so use the object definition as a guide.
      $defn = $this->defn();

      // Check to see if the definition contains a template
      if(isset($defn->template) and !empty($defn->template))
        // Yes, so just use it.
        return $this->js($sep, $defn->template);

      // First, do the flat properties.
      if(isset($defn->columns))
        foreach ($defn->columns as $col=>$alias) {
          // If the value is true, then use the normal property name
          // ($col) as the JS property.  Otherwise, use the value as the
          // property name.
          $acol = $alias===true?$col:$alias;

          // If there is data for this property, buffer the serialised k/v
          // pair, with $acol as the key.
          if(!empty($acol) and !empty($this->_data[$col]))
            $buf[] = $this->js_literal($acol, $this->_data[$col], $sep);
        }

      // Then, do the links.
      if(isset($defn->links))
        foreach ($defn->links as $ln=>$la) {
          // Since we're not dealing with a template, we can just use
          // js_literal for the recursion.  Output the serialised k/v
          // pair.
          if(!empty($ln) and !empty($this->_data[$ln]))
            $buf[] = $this->js_literal($ln, $this->_data[$ln], $sep);
        }

      // Wrapper the buffer in a JS array definition.
      return '{'.$sep.join(','.$sep, $buf).$sep.'}';
    }
  }



  /**
   * Used by js() to output a given key/value pair, correctly escaped.
   *
   * @internal
   * @ignore
   */
  protected function js_literal($k, $v, $sep="") {
    // Used by js() to output a given key/value pair, correctly escaped.
    if(!is_numeric($k))
      $k = "'$k'";

    if(empty($v))
      return "$k:null";

    if(is_numeric($v))
      return "$k:$v";

    if(is_string($v))
      return "$k:\"".preg_replace('/[\n\r]+/', '\n', addslashes($v)).'"';

    if(is_array($v) or $v instanceof DatabaseArray) {
      // Array: this is primarily used when serialising without a
      // template.
      $buf = array();
      foreach ($v as $k2=>$v2)
        $buf[] = $this->js_literal($k2, $v2, $sep);

      return "$k:{".$sep.join(','.$sep, $buf).$sep.'}';
    }

    if(is_object($v) and $v instanceof DatabaseObject)
      // This is quite unlikely: a sub-DatabaseObject is meant to be
      // created with a link. However, if this is done ad-hoc (outside the
      // normal DatabaseObjectDefinition) it may be used.  However, it's
      // pretty much untested.
      return "$k:".$v->js($sep);

    // Something else.  XXX: This really should be an exception.
    return "$k:\"".addslashes($v)."\"";
  }
};
