<?php

require_once ('DatabaseObject/common/vars.php');

if(defined('MEMCACHE_TRYUSE') and MEMCACHE_TRYUSE) {
  $MEMCACHE = new Memcache;
  if(@$MEMCACHE->pconnect(MEMCACHE_HOST, MEMCACHE_PORT))
	// XXX: Should be some sort of logging here.
	define('MEMCACHE_USE', true);
}
