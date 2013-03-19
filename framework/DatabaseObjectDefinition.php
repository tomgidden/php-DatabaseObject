<?php
/**
 * Database Abstraction Layer Framework
 *
 * @author		Tom Gidden <tom@gidden.net>
 * @copyright	Tom Gidden, 2009
 * @version		$Id$
 * @package		DatabaseObject
 * @subpackage	framework
 * @filesource
 */

/**
 * Includes
 */
require_once('DatabaseObject/framework/DatabaseHandle.php');


/**
 * Base class for all definitions of database-backed objects.
 *
 * DatabaseObjectDefinition is a simple object containing the details of
 * the object's storage on the database.  Ideally, this would be a static
 * property of the DatabaseObject, but if it was, then the methods defined
 * here wouldn't access the subclass' values.
 *
 * All definition objects should be named as the objects they define, plus
 * the suffix '_defn'.  This is hardcoded into the constructor.  As an
 * example, a {@link DatabaseObject} subclass called 'User' should have a
 * corresponding {@link DatabaseObjectDefinition} subclass called
 * 'User_defn'.
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
 * @package     DatabaseObject
 * @subpackage  framework
 */
class DatabaseObjectDefinition {


  /**
   * The object class to be created
   *
   * $classname should be a string containing the name of the {@link
   * DatabaseObject} subclass that this definition should be used to
   * instantiate.  It should always be the name of this class with the
   * '_defn' suffix removed.
   *
   * @var string
   */
  public $classname;



  /**
   * The name of the table on the database
   *
   * The corresponding table for this object type in the database.  Often
   * the same (but lowercased) as $classname.
   *
   * @var string
   */
  public $tablename;



  /**
   * The columns used as the primary key
   *
   * Either a string or an array of strings containing the name(s) of the
   * primary key(s).  Note, even if the columns are aliased to new names
   * in {@link $columns}, these keys are as written in the database rather
   * than the aliases.
   *
   * Example:
   * <pre>
   *    public $idcolumns = array('template_id', 'page_number');
   * </pre>
   *
   * @var array|string
   */
  public $idcolumns;



  /**
   * An array of Links/Joins to other tables/objects
   *
   * A link describes a possible join to another table in the database.
   *
   * @var array
   */
  public $links;



  /**
   * What columns, and what aliases for them.
   *
   *
   *
   * @var string
   */
  public $columns;



  /**
   * Object's default memcache timeout.
   *
   *
   *
   * @var string
   */
  public $memcache_timeout;



  /**
   * A register of instances indexed by classname
   *
   *
   *
   * @var string
   */
  protected static $instances = array();



  /*private*/ protected function __construct() {}



  /**
   * Singleton constructor for a given DatabaseObjectDefinition.
   *
   * This function is effectively the constructor for this class.  To
   * call, use: $defn = DatabaseObjectDefinition::get_instance('Foo');
   * although there aren't many reasons to use this outside
   * DatabaseObject.  A singleton: get the instance for the given
   * $classname.
   *
   * @var DatabaseObjectDefinition
   */
  public static function get_instance($classname) {

	// Check the cache to see if it has already been instantiated
	if(isset(DatabaseObjectDefinition::$instances[$classname]))
	  // Yes, so just return it.
	  return DatabaseObjectDefinition::$instances[$classname];

	// Check memcached for a copy instead.
	if(defined('MEMCACHE_USE') and MEMCACHE_USE) {
	  global $MEMCACHE;

	  // If there's a memcache...
	  if($MEMCACHE) {
		// Look for instance in memcache
		if($instance = $MEMCACHE->get($memcache_key = MEMCACHE_PREFIX.'DOD:'.$classname)) {
		  // Insert into the Level 1 cache
		  DatabaseObjectDefinition::$instances[$classname] = $instance;
		  return $instance;
		}

		// Otherwise, create one
		$defnname = $classname.'_defn';
		$instance = new $defnname();

		// and cache it locally
		DatabaseObjectDefinition::$instances[$classname] = $instance;

		// and insert it into the memcache.
		$MEMCACHE->set($memcache_key, $instance, false, MEMCACHE_TIMEOUT_DOD);

		return $instance;
	  }
	}

	// Not yet, so create it, cache it and return it
	$defnname = $classname.'_defn';
	return DatabaseObjectDefinition::$instances[$classname] = new $defnname();
  }

  /*protected*/ public function decide_limits(&$linkarr, &$link_path, $cur_level, &$limit_overrides=null, &$query_link=null) {
	// Return (true/false) whether or not to recurse based on limits
	// (global and per-object) and the current level.
	//
	// $query_link is used to construct queries where the link would not
	// usually be followed due to limits.  It effectively overrides the
	// limit functions if $query_link is the same as the $link_path passed
	// to it.
	//
	// It is public solely for the use of DatabaseObject.

	// Check overrides
	if(!is_null($limit_overrides) and isset($limit_overrides[$link_path]))
	  if($limit_overrides[$link_path] === true)
		return true;
	  else if($limit_overrides[$link_path] === false)
		return false;
	  else if($limit_overrides[$link_path] >= $cur_level)
		return false;
	  else
		return true;

	// If there are specific links to be dereferenced...
	if(isset($query_link)) {
	  if($link_path == $query_link)
		// This is a specifically requested link, so TRUE
		return true;
	  else
		// This link is NOT specifically requested, so FALSE
		return false;
	}

	// If there is a global limit and it has been reached, then FALSE
	if(isset($linkarr['limit']) and $linkarr['limit']>=$cur_level)
	  return false;

	// If there are no per-object limits, then TRUE
	if(!isset($linkarr['limits']))
	  return true;

	// If there is no per-object limit for this path, then FALSE: if
	// there are any per-object limits, then ones that aren't specified
	// should be totally limited.
	if(!isset($linkarr['limits'][$link_path]))
	  return false;

	// If the per-object limit is false, then it's not conditional: FALSE.
	if($linkarr['limits'][$link_path] === false)
	  return false;

	// If the per-object limit is true, then it's infinite: TRUE
	if($linkarr['limits'][$link_path] === true)
	  return true;

	// If the per-object limit has not been reached, TRUE
	if($linkarr['limits'][$link_path]<$cur_level)
	  return true;

	// Shouldn't get here.
	return false;
  }

  /*protected*/ public function skip_row_object_and_children(&$pointer, $cur_level, $table_path, $limit_overrides, $query_link=null) {
	// This function will use the definition to "fast forward" through a
	// row, over the object's columns AND its children.  If the columns
	// are known to be null (as a result of the foreign key in a link
	// being null), then there's no point in copying them.  This function
	// will just jump forward.

	// Fast forward over the columns for the base object
	$pointer += sizeof($this->columns);

	// Skip over the children if necessary
	$this->skip_row_children($pointer, $cur_level, $table_path, $limit_overrides, null);
  }

  /*protected*/ public function skip_row_children(&$pointer, &$cur_level, &$table_path, $limit_overrides, $query_link=null) {
	// This function will use the definition to "fast forward" through a
	// row, over JUST the object's children.  If the columns are known to
	// be null (as a result of the foreign key in a link being null), then
	// there's no point in copying them.  This function will just jump
	// forward.

	// If the object has children...
	if(isset($this->links))

	  // For each child object that's not limited in this context, skip
	  // the data.
	  foreach ($this->links as $linkname=>$linkarr) {

		// Since the query definition will have respected limits, we need
		// to do so as well.
		$link_path = $table_path.'_'.$linkname;

		// Check if the subobject should be traversed.
		if($this->decide_limits($linkarr, $link_path, $cur_level, $limit_overrides, $query_link)) {

		  // This object is not to be skipped.  As a result, we need to
		  // recurse, using the subobject's definition.
		  $linkdefn = DatabaseObjectDefinition::get_instance($linkarr['classname']);
		  $linkdefn->skip_row_object_and_children($pointer, $cur_level+1, $link_path, $limit_overrides);
		}
	  }
  }

  public function clauses($limit_overrides=null, &$query_link=null) {
	// This function builds the components for a joined SELECT query on
	// the object. When run without $query_link, it will result in a query
	// for retrieving the object by primary key(s), using placeholders.
	//
	// If, however, the parameter $query_link is included, it is used to
	// force the query to be generated for a given subobject which would
	// presumably be deferred in the normal query.  The parameter should
	// consist of the link path.  For example, to query
	// $u->org->subscriptions (where $u is of type User), the path would
	// be "User_org_subscriptions".
	//
	// The first parameter, $limit_overrides, allows override of link
	// limits on a per-query basis.  It should be an array identical in
	// format and arrangement to the 'limits' property of a
	// DatabaseObject.
	//
	// This function is wrappered to allow the main _clauses to be
	// protected.
	return $this->_clauses(null, 0, null, false, $limit_overrides, $query_link);
  }

  protected function _clauses($table_path = null,
							  $cur_level = 0,
							  $join_condition = null,
							  $tainted_with_null = false,
							  $limit_overrides = null,
							  &$query_link = null) {
	// This function builds the components for a joined SELECT query on
	// the object.  Run without parameters, it will result in a query for
	// retrieving the object by primary key(s), using placeholders.
	//
	// The first, second and third parameters are private recursion
	// variables.  $table_path is the path for this object, which is set
	// to the classname at the root.  $cur_level is the recursion depth
	// for the purpose of numeric limiting of recursion.  $join_condition
	// makes up for the fact that the SQL syntax for joins isn't treated
	// in a nested fashion.
	//
	// The fourth parameter, $tainted_with_null, indicates that a link
	// earlier in the tree was marked 'nullable', and so all future links
	// need to be 'nullable' (ie. LEFT OUTER JOINed) to make the query
	// work.
	//
	// The fifth parameter, $limit_overrides, allows override of link
	// limits on a per-query basis.  It should be an array identical in
	// format and arrangement to the 'limits' property of a
	// DatabaseObject.
	//
	// The sixth parameter, $query_link, forces the query to be generated
	// for a given subobject path which would presumably be deferred in a
	// normal query.


	// If the table path wasn't supplied, then it is (probably) the root
	// of a tree.  So, use the object's classname to start the path.
	if(is_null($table_path))
	  $table_path = $this->classname;

	// Start building the columns:
	if(is_null($query_link))
	  // The query is a normal one, then the resulting row should start
	  // with the columns from the base object, so include them.
	  $cols = $this->column_clauses($table_path);
	else
	  // The query is a forced deferral.  As a result, we already know the
	  // base object, and we're just interested in using it for JOINing.
	  // So, we don't care about the base object data.
	  $cols = '';


	// Build the table declaration for this object (non-recursive: this
	// will just result in the aliased table name)
	$from = $this->from_clauses($table_path);

	// Start building the WHERE clauses.  Very basic: this will only do
	// WHEREs for the base object, as anything more complex is going to be
	// handled by the caller.
	if($cur_level==0)
	  // This is a base object, so include WHERE clauses for the primary key.
	  $where = $this->where_clauses($table_path);
	else
	  // It's a subobject, so we don't bother with WHEREs.
	  $where = '';

	// If a JOIN condition was supplied, then it needs to be added before
	// the links are processed.  Otherwise, a situation like this will
	// occur:
	//     T1 t1 INNER JOIN T2 t2 INNER JOIN T3 t3 ON t3.id = t2.id ON t2.id = t1.id
	// rather than the correct
	//     T1 t1 INNER JOIN T2 t2 ON t2.id = t1.id INNER JOIN T3 t3 ON t3.id = t2.id
	//
	// SQL syntax SUCKS in my opinion.
	if(!is_null($join_condition))
	  $from .= $join_condition;

	// Now process the object's subobject links (if there are any)
	if(isset($this->links)) {

	  // For each link...
	  foreach ($this->links as $linkname=>$linkarr) {
		// The table path for a subobject is the base object's name,
		// followed by the link name.
		$link_path = $table_path.'_'.$linkname;

		// If there is a forced link path, then the original table path
		// needs prepending onto it.  Purely cosmetic, to make the
		// original call simpler.
		if(!is_null($query_link))
		  $try_link = $table_path.'_'.$query_link;

		// First, decide whether this link should be processed or not.  We
		// use the same decide_limits() rule as we will later use for
		// parsing the results.
		if($this->decide_limits($linkarr, $link_path, $cur_level, $limit_overrides, $try_link)) {

		  // Get the definition for the subobject
		  $linkdefn = self::get_instance($linkarr['classname']);

		  // To do aggregate foreign keys, we must build the JOIN
		  // condition slightly differently, so firstly check what format
		  // the foreign key is in.
		  if(is_string($linkarr['foreign_key'])) {
			// The foreign key specification is a simple string, so we can
			// just use it as is.

			// Build the JOIN condition between this current object and the
			// new subobject. We will need to pass this along when we
			// recurse to prevent the JOIN problems highlighted above.
			$njoin = ' ON '.$table_path.'.'.$linkarr['foreign_key'].'='.$link_path.'.'.$linkarr['foreign_key'];

		  } else if(is_array($linkarr['foreign_key'])) {
			// The foreign key specification is an array, so we need to
			// build the condition.

			// Build an array of JOIN conditions
			$njoin = array();

			// Add a condition for each foreign key part.
			foreach ($linkarr['foreign_key'] as $fk=>$ref)
			  if(is_numeric($fk)) // Check for non-associative array
				$njoin[] = "$table_path.$ref=$link_path.$ref";
			  else
				$njoin[] = "$table_path.$fk=$link_path.$ref";

			// If there were no entries, then this is a faulty foreign key
			// specification which would result in a cartesian JOIN. Erk!
			if(!count($njoin))
			  throw new Exception("Faulty foreign key specification on $linkname");

			// Convert the array into a correct SQL JOIN condition.
			$njoin = ' ON '.join(' AND ', $njoin);

		  } else
			// It's not an array or a string, so we're buggered here.
			throw new Exception("Faulty foreign key specification on $linkname");


		  // If the link is nullable, then a LEFT OUTER JOIN is
		  // necessary. Otherwise, an INNER JOIN will do.  If a previous
		  // link was nullable, then this one (and all children) must be
		  // too.
		  if($linkarr['nullable'] or $tainted_with_null==true) {
			$from .= ' LEFT OUTER JOIN ';
			$tainted_with_null = true;
		  }
		  else
			$from .= ' INNER JOIN ';

		  // Do the clauses for this link, recursing.  The JOIN condition
		  // needs to be supplied since it needs to be inserted by the
		  // subobject's from_clauses call BEFORE the that subobject
		  // recurses.  If this was being done with subqueries, then
		  // nesting would obviate that.
		  list($ncols, $nfrom) = $linkdefn->_clauses($link_path, $cur_level+1, $njoin, $tainted_with_null, $limit_overrides);

		  // Add the new FROM clause (which will include those of
		  // subobjects) to the table definition.
		  $from .= $nfrom;

		  // Add the subobject's columns to the existing columns (if any)
		  $cols = empty($cols) ? $ncols : "$cols,$ncols";
		}
	  }
	}

	// If there's no WHERE clauses, don't bother returning it.
	if(empty($where))
	  return array($cols, $from);
	else
	  return array($cols, $from, $where);
  }

  protected function column_clauses(&$table_path) {
	// This will list the columns for the current object, prefixing with
	// the table path.  This will NOT alias the columns, so reading the
	// results associatively is a bad plan.  By virtue of using the same
	// code to build the query as parse the results, we can already know
	// what order the columns are in, so we can skip column name lookups.
	if(!isset($this->columns))
	  throw new Exception('No columns known for '.get_class($this));

	// For each column, add it to a buffer array
	$cols = array();
	foreach ($this->columns as $k=>$v)
	  $cols[] = $table_path.'.'.$k;

	// Return it as a comma-separated list.
	return join(',',$cols);
  }

  public function where_clauses(&$table_path) {
	// This will build a string of WHERE clauses for doing a simple
	// primary key lookup using placeholders.  Chances are, for anything
	// other than basic retrieval, this will be absolutely no use to you.

	// $cols is the list of primary key components, or just the sole
	// primary key component if it's not an aggregate.
	$cols = &$this->idcolumns;

	if(is_array($cols)) {
	  // The primary key is aggregate, so we need to build an array of
	  // simple "colname=?" clauses.
	  $idwhere = array();
	  foreach ($cols as $col)
		$idwhere[] = $table_path.'.'.$col.'=?';

	  // Join the clauses together as a single ANDed and grouped clause.
	  return '('.join(' AND ', $idwhere).')';
	}
	else if(is_string($cols)) {
	  // The primary key is a simple column, so the WHERE clause is a
	  // simple "colname=?" string.
	  return $table_path.'.'.$cols.'=?';
	}

	// The idcolumns property is not a string or an array, so something's
	// probably wrong with the definition.
	throw new Exception('Bad idcolumns specification in '.get_class($this));
  }

  public function from_clauses(&$table_path) {
	// This will return the table declaration with alias for the FROM clause.
	return $this->tablename.' '.$table_path;
  }
};
