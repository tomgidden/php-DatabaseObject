<?php

ini_set('display_errors', true);

@include_once('config.php');

if(!defined('DSN')) {
  define('DSN', 'mysql:host=localhost;dbname=sample_db');
  define('DSN_USERNAME', 'sample');
  define('DSN_PASSWORD', 'sample_pw');
}

# define('MEMCACHE_TRYUSE', true); // MEMCACHE_USE is defined if connect is successful
# define('MEMCACHE_HOST', 'localhost');
# define('MEMCACHE_PORT', 11211);
# define('MEMCACHE_PREFIX', 'dbg_');
# define('MEMCACHE_TIMEOUT_DOD', 3600);
# define('MEMCACHE_TIMEOUT_DOSQL', 3600);

// XXX: Note, retries are currently disabled.  See framework/DatabaseHandle.php:136 onwards
define('DATABASE_RETRY_LIMIT', 6); // A maximum of 6 successive retries for a given query
define('DATABASE_RETRY_WAIT', 2); // 2 seconds between successive query retries
