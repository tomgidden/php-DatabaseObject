<?php
  /* DatabaseHandle
   * by Tom Gidden <tom@gidden.net>
   * Copyright (C) 2009, Tom Gidden
   */

require_once('DatabaseObject/common/vars.php');

class ConnectionException extends Exception {};
class QueryErrorException extends Exception {};
class ResultsErrorException extends Exception {};

class DatabaseHandle {
  /*
   * This class wrappers the PDO DB code, offering a singleton (for any
   * given DSN) and basic prepared query caching.  It also takes the
   * opportunity to profile the queries using the TIMINGS constant to
   * activate.
   *
   * The prepared query caching may not be necessary, as I think DB.php
   * does this itself.
   *
   * To obtain a database handle, call:
   *
   *     $dbh = DatabaseHandle::get_instance()
   */

  private static $instances = array(); // One DatabaseHandle per DSN
  protected $cache = array();		   // The prepared query cache
  protected $sqllog = array();		   // Log of queries run through this handle
  protected $dbh;					   // The DB handle itself

  protected $lastRowCount;

  private function __construct($dsn, $username, $password) {
	// This is effectively a singleton (per DSN), so the constructor is
	// private.

	// May throw an exception.
	$this->dbh = new PDO($dsn, $username, $password);
  }

  private function __clone() {}

  public static function get_instance($dsn=null) {
	// Get the DatabaseHandle for a DSN, creating one if necessary.
	//
	// Call:   $dbh = DatabaseHandle::get_instance($dsn);

	// If there is no DSN, and there's a global one defined, use it.
	if(is_null($dsn))
	  if(defined('DSN'))
		$dsn = DSN;
	  else
		return null;

	if(defined('DSN_USERNAME')) {
	  $dsn .= ':user='.DSN_USERNAME;
	  $username = DSN_USERNAME;
	}
	else
	  $username = null;

	if(defined('DSN_PASSWORD')) {
	  $dsn .= ':password='.DSN_PASSWORD;
	  $password = DSN_PASSWORD;
	}
	else
	  $password = null;

	// If the DatabaseHandle already exists, return it.
	if(isset(self::$instances[$dsn]))
	  return self::$instances[$dsn];

	// Otherwise create it and return it.
	return self::$instances[$dsn] = new DatabaseHandle(DSN, $username, $password);
  }

  protected function prepare($sql) {
	// Prepares a 'prepared statement', using the PDO prepare() call.  In
	// addition, it will also cache that query.  I can't tell if PDO has
	// this functionality, but it doesn't seem to be documented.
	// Anyway, the caching is relatively low-cost, so might as well do it
	// here.

	if(isset($this->cache[$sql]))
	  return $this->cache[$sql];

	return $this->cache[$sql] = $this->dbh->prepare($sql);
  }

  public function query($sql, $params=null, $with_exceptions=false) {
	// Executes a query, using prepared statements.  It will return the
	// DB_result or DB_Error object.  Parameters should be passed in an
	// array, as a single scalar if there's only one, or null if there are
	// no placeholders.

	// Log the start time of the query
	if(defined('TIMINGS') and TIMINGS) {
	  list($usec, $sec) = explode(" ",microtime());
	  $start =  ((float)$usec + (float)$sec);
	}

	// $r: the result object
	$r = null;

	// $msg:  the error message, if any
	$msg = null;

	try {
	  // Prepare the query
	  $q = $this->prepare($sql);

	  // The query should be repeated if a deadlock or failed connect
	  // occurs, so $c counts the number of attempts.
	  $c=0;

	  do {
		// If this is not the first time around (ie. a deadlock), then sleep
		if($c++) sleep($c*DATABASE_RETRY_WAIT);

		// Execute the query, binding parameters if necessary.
		try {
		  if(isset($params))
			if(is_array($params))
			  $r = $q->execute($params);
			else
			  $r = $q->execute(array($params));
		  else
			$r = $q->execute();
		}
		catch (Exception $e) {
		  $this->sqllog[] = array(false, 0, 'Exception: '.$msg->getMessage(), $sql);
		  throw $e;
		}

		// $retry will be set to true if the query needs to be retried.
		$retry = false;

		// XXX: This bit needs fixin'
		//
		if(!$r) {
		  if($q->errorCode() == '00000') {
			$retry = false; // All okay, but just no results.
		  }
		  else {
			$msg = $this->errorMessage($q);
			$retry = false;
		  }
		}
		/*
		// If there's an error in the result set (NOT the query prepare),
		// then if it's a deadlock or a failed connect, then do a retry.
		if(!$r) {
		  //		  switch($q->errorCode()) {
		  //		  case DB_ERROR_NOT_LOCKED:
		  //		  case DB_ERROR_CONNECT_FAILED: // XXX?
			$retry = true;
			break;
		  }
		}
		*/

		// This will only loop if the query failed softly
	  } while($retry and $c<=DATABASE_RETRY_LIMIT);
	}
	catch (PDOException $e) {
	  $msg = $e->getMessage();
	}

	// Remove the stupid SQL dump at the start of the message
	if(!is_null($msg))
	  if(preg_match('/^.*?\[nativecode=([^\]]+)\]$/', $msg, $parts))
		$msg = $parts[1];

	// Log the end time of the query, and the error if necessary.
	if(defined('TIMINGS') and TIMINGS) {
	  list($usec, $sec) = explode(" ",microtime());
	  $end =  ((float)$usec + (float)$sec);

	  // If $msg is still null, then everything's fine
	  if(is_null($msg)) {
		$this->sqllog[] = array(true, $end-$start, $sql, $params, null);
		return $q;
	  } else
		$this->sqllog[] = array(false, $end-$start, $sql, $params, $msg);
	}

	if(!is_null($msg)) {
	  if(!$q)
		if($with_exceptions)
		  throw new QueryErrorException($msg);
		else
		  return $q;
	  else if(!$r)
		if($with_exceptions)
		  throw new ResultsErrorException($msg);
		else
		  return $q;
	  else
		return $q;
	}
	else {
	  $this->lastRowCount = $q->rowCount();
	  return $q;
	}
  }

  public function add_to_log($text, $time, $success=true, $msg=null) {
	$this->sqllog[] = array($success, $time, $text, null, $msg);
  }

  public function query_with_exceptions($sql, &$params=null) {
	// Executes a query as with ::query(), but with an exception being
	// thrown if there's an SQL error.
	return $this->query($sql, $params, true);
  }

  public function html($sep="\n") {
	// Serialise to HTML. Almost widgety.
	return "<!--".$sep.$this->text($sep).$sep."-->".$sep;
  }

  public function affectedRows() {
	return $this->lastRowCount;
  }

  public function autoCommit($bool) {
	// Log the start time of the query
	if(defined('TIMINGS') and TIMINGS) {
	  list($usec, $sec) = explode(" ",microtime());
	  $start =  ((float)$usec + (float)$sec);
	}

	if($bool) {
	  if(!($this->dbh->getAttribute(constant('PDO::ATTR_AUTOCOMMIT')))) {
		$this->sqllog[] = array(false, 0, 'Weird rollback', '');
		$this->dbh->rollBack();
	  }

	  // XXX: Erk.  This whole thing is really thanks to PEAR DB, and it
	  // doesn't map well to PDO.
	}
	else {
	  $x = $this->dbh->beginTransaction();
	}

	// Log the end time of the query, and the error if necessary.
	if(defined('TIMINGS') and TIMINGS) {
	  list($usec, $sec) = explode(" ",microtime());
	  $end =  ((float)$usec + (float)$sec);

	  // If $x, then everything's fine
	  if(!$x) {
		$msg = $this->errorMessage();
		$this->sqllog[] = array(false, $end-$start, 'Autocommit '.($bool?'true':'false'), $msg);
	  } else
		$this->sqllog[] = array(true, $end-$start, 'Autocommit '.($bool?'true':'false'), null, null);
	}

	return $x;
  }

  public function rollback() {
	// Log the start time of the query
	if(defined('TIMINGS') and TIMINGS) {
	  list($usec, $sec) = explode(" ",microtime());
	  $start =  ((float)$usec + (float)$sec);
	}

	$x = $this->dbh->rollBack();

	// Log the end time of the query, and the error if necessary.
	if(defined('TIMINGS') and TIMINGS) {
	  list($usec, $sec) = explode(" ",microtime());
	  $end =  ((float)$usec + (float)$sec);

	  // If $x, then everything's fine
	  if(!$x) {
		$msg = $this->errorMessage();
		$this->sqllog[] = array(false, $end-$start, 'Rollback', $msg);
	  } else
		$this->sqllog[] = array(true, $end-$start, 'Rollback', null, null);
	}

	return $x;
  }

  public function commit() {
	// Log the start time of the query
	if(defined('TIMINGS') and TIMINGS) {
	  list($usec, $sec) = explode(" ",microtime());
	  $start =  ((float)$usec + (float)$sec);
	}

	$x = $this->dbh->commit();

	// Log the end time of the query, and the error if necessary.
	if(defined('TIMINGS') and TIMINGS) {
	  list($usec, $sec) = explode(" ",microtime());
	  $end =  ((float)$usec + (float)$sec);

	  // If $x, then everything's fine
	  if(!$x) {
		$msg = $this->errorMessage();
		$this->sqllog[] = array(false, $end-$start, 'Commit', $msg);
	  } else
		$this->sqllog[] = array(true, $end-$start, 'Commit', null, null);
	}

	return $x;
  }

  public function nextId($seq) {
	throw new Exception("nextId is not currently implemented");
	return $this->dbh->lastInsertId($seq)+1;
  }


  public function lastInsertId() {
	return $this->dbh->lastInsertId();
  }


  public function errorMessage($q=null) {
	if($q) {
	  return join(' ', $q->errorInfo());
	}
	else {
	  return join(' ', $this->dbh->errorInfo());
	}
  }

  public function text($sep="\n") {
	// Serialise to plain (but formatted) text.
	$buf = array();
	$total_duration = 0;

	// For each executed query...
	foreach ($this->sqllog as $arr) {
	  $str = '';

	  // $arr[0] is success/failure
	  if($arr[0])
		$str = 'Y ';
	  else
		$str = 'N ';

	  // $arr[1] is the duration of the query,
	  // $arr[2] is the query SQL itself
	  $str .= $arr[1].': '.$arr[2];

	  // If there are parameters for the query (prepared statement), then
	  // they're in $arr[3].
	  if(is_array($arr[3]))		// Array supplied
		$str .= ' ('.join(',',$arr[3]).')';
	  else if(isset($arr[3]))	// Scalar supplied
		$str .= ' ('.$arr[3].')';

	  // $arr[4] contains any message from the result
	  if(isset($arr[4]))
		$str .= ': '.$arr[4];

	  // Add to output buffer
	  $buf[] = $str;

	  // Tot up the total duration time from the query duration
	  $total_duration += $arr[1];
	}

	$buf[] = "Total: $total_duration";

	return join($sep, $buf);
  }
};
