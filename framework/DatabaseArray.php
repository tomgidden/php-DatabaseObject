<?php
  /* DatabaseArray
   * by Tom Gidden <tom@gidden.net>
   * Copyright (C) 2009, Tom Gidden
   */

require_once('DatabaseObject/common/vars.php');
require_once('DatabaseObject/framework/DatabaseObject.php');
require_once('DatabaseObject/framework/JavascriptSerialisable.php');


/*
 * This whole object is quite problematic: PHP5 doesn't seem to give the
 * ArrayObject mechanism the full capabilities of an array.
 *
 * Especially, array_{pop,push,shift,unshift,keys} and other functions are
 * not easily done. Do they need to be added as custom methods,
 * eg. ::pop() ?
 */

class DatabaseArray extends ArrayObject implements JavascriptSerialisable {

  public function last() {
	return $this->getIterator()->current();
  }

  public static function js_serialise(&$arr, $sep="", &$template=null, $as_hash=false) {
	// Returns a Javascript serialisation of the array.  If $as_hash, then
	// output a k/v-based object.
	$buf = array();

	if($as_hash) {
	  foreach ($arr as $k=>$v) {
		$v2 = $v->js($sep, $template);
		if(!is_numeric($k)) $k = "'$k'";
		if(!empty($v2)) $buf[] = $k.':'.$v2;
	  }
	  return '{'.join(','.$sep, $buf).'}';
	} else {
	  foreach ($arr as $v) {
		$v2 = $v->js($sep, $template);
		if(!empty($v2)) $buf[] = $v2;
	  }
	  return '['.join(','.$sep, $buf).']';
	}
  }

  public function keys() {
	return array_keys((array)$this);
  }

  public function js($sep="", &$template=null, $as_hash=false) {
	// Returns a Javascript serialisation of this object's array.  If
	// $as_hash, then output a k/v-based object.
	return self::js_serialise($this, $sep, $template, $as_hash);
  }

};
