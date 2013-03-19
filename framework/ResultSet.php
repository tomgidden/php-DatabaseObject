<?php
  /* ResultSet model
   * by Tom Gidden <tom@gidden.net>
   * Copyright (C) Tom Gidden, 2009
   */

require_once('DatabaseObject/framework/JavascriptSerialisable.php');

abstract class ResultSet extends ArrayObject implements JavascriptSerialisable {

  public function js_serialise($sep="", &$template=null, $as_hash=false) {
	// Returns a Javascript serialisation of the array.  If $as_hash, then
	// output a k/v-based object.
	$buf = array();

	$i = $this->getIterator();

	if($as_hash) {
	  foreach ($i as $k=>$v) {
		$v2 = $v->js($sep, &$template);
		if(!empty($v2)) $buf[] = $k.':'.$v2;
	  }
	  return '{'.join(','.$sep, $buf).'}';
	} else {
	  foreach ($i as $v) {
		$v2 = $v->js($sep, &$template);
		if(!empty($v2)) $buf[] = $v2;
	  }
	  return '['.join(','.$sep, $buf).']';
	}
  }

  public function js($sep="", &$template=null, $as_hash=false) {
	// Returns a Javascript serialisation of this object's array.  If
	// $as_hash, then output a k/v-based object.
	return $this->js_serialise(&$sep, &$template, $as_hash);
  }
};
