<?php if (!defined('ADDIAH')) header('Location: ../') && die; // ex: softtabstop=3 shiftwidth=3 tabstop=3 expandtab
/**
 * Addiah PHP Application Framework: Database module, MySQL based
 * (c) 2006-2013 Ho Yiu YEUNG
 *
 * This file is part of Addiah.
 * Addiah is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * Addiah is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License along with Addiah.  If not, see <http://www.gnu.org/licenses/>.
 */

global $db_qs, $db_qe, $db_sp;
$db_qs = '`';
$db_qe = '`';
$db_sp = '';

/** Module self-diagnose */
function db_mysql_test() {
  global $config;
  if (!function_exists('mysql_connect')) {
    test_error('MySQL extension not loaded');
  } else if (db_connect()) {
    echo "MySQL version ".db_get_version().'<br/>';
    echo "Database: $config[db_name]";
  } else
    test_error('Database connection FAIL');
}



/**
 * Internal cornerstone query method used by everything else.
 * Performance can be critical.
 */
function _db_query( $query ) {
   db_connect();
   $query = _db_prepare( func_get_args() );
   assert( is_string( $query ) );
   addiah_log_time( 'db', $query );
   $result = mysql_query( $query, db_get_connection() );
   addiah_log_time( 'db' );
   return $result;
}

/**
 * Manually process parameterised query
 * @param string $query Query to process
 * @return string Processed query
 */
function _db_prepare( $query ) {
   // Escape parameters
   if ( is_array( $query ) ) {
      $params = $query;
      $query = reset( $params );
   } else {
      $params = func_get_args();
   }
   if ( sizeof( $params ) > 1 ) {
      $param = array_slice( $params, 1 );
      // TODO: Handle date object.
      if ( function_exists( 'db_value' ) ) $param = array_map( 'db_value', $param );
      else {
         foreach ( $param as $k => $p )
            if ( ! is_numeric( $p ) ) $param[$k] = "'".db_escape($p)."'";
      }
      $match = array();
      preg_match_all( '~(?<=\W)\?(?![\'?])~', $query, $match, PREG_OFFSET_CAPTURE );
      $match = $match[0];
      for ( $i = sizeof( $match )-1 ; $i >= 0 ; $i -- ) {
         $query = substr( $query, 0, $match[$i][1] ) . $param[$i] . substr( $query, $match[$i][1]+1 ) ;
      }
   }
   return $query;
}

/**
 * Connect to database, set db to use, and set connection charset to utf8.
 * When cannot connect to database, a PHP warning will be raised.
 *
 * @return boolean True if database can connect, false otherwise.
 */
function db_connect() {
   global $config, $addiah;
   $conn = db_get_connection();
   if ( $conn ) return $conn;
   if ( ! function_exists('mysql_connect') ) return trigger_warning('MySQL PHP module not installed/loaded.');
   addiah_log_time( 'db', '(Connect)' );
   $addiah['_db_conn'] = @mysql_connect( $config['db_server'], $config['db_user'], $config['db_pass'] );
   addiah_log_time( 'db' );
   if ($addiah['_db_conn'] === false) return trigger_warning( '[Addiah.db] Cannot connect to database.' );
   if ( ! mysql_select_db( $config['db_name'] ) ) return trigger_warning( '[Addiah.db] Cannot select database.' );
   _db_query("SET NAMES 'UTF8'");
   return $addiah['_db_conn'];
}

/**
 * Get database version
 */
function db_get_version() {
  return db_connect() ? mysql_get_server_info( db_get_connection() ) : false;
}

/**
 * Get a single row from result as associative array
 *
 * @param int $offset Row to skip fetching. First row is 0.
 * @param string $query Query to execute
 * @throws InvalidArgumentException If query cannot be executed.
 */
function db_read( $offset, $query = null ) {
   if ( is_string( $offset ) ) {
      $sql = $offset;
      $offset = 0;
      $params = func_get_args();
   } else {
      $sql = $query;
      $params = array_slice( func_get_args(), 1 );
   }
   $sql = trim( $sql );

   // If it is a simple query without LIMIT, add LIMIT offset,1 to it
   if ( startsWith( $sql, array( 'select', 'SELECT' ) ) &&
           ! preg_match( '~\bLIMIT\s+\d+(,\d+|\s+OFFSET\s+\d+)?\s*$~i', $sql ) ) {
      $sql .= $offset ? " LIMIT $offset,1" : " LIMIT 1";
      $offset = 0;
   }
   $params[0] = $sql;

   db_connect();
   $sql = _db_prepare( $params );
   addiah_log_time( 'db', $sql );
   $res = mysql_query( $sql );
   if ( $res === false ) db_get_errors( $sql );

   if ( $offset ) while ( $offset-- > 0 ) if ( ! mysql_fetch_assoc( $res ) ) return false;
   $result = mysql_fetch_assoc( $res );

   mysql_free_result( $res );
   addiah_log_time( 'db' );

   if ($result && sizeof($result) == 1) $result = reset($result);
   return $result;
}

/**
 * Get all returned rows as associative array.
 *
 * @param int $offset Row to skip fetching. First row is 0.
 * @param int $count Max. rows to fetch. May return less.
 * @param string $query Query to execute
 * @throws InvalidArgumentException If query cannot be executed.
 */
function db_read_all( $offset, $count = null, $query  = null ) {
   if ( is_string( $offset ) ) {
      $sql = $offset;
      $params = func_get_args();
      $offset = $count = 0;
   } else if ( is_string( $count ) ) {
      $sql = $count;
      $params = array_slice( func_get_args(), 1 );
      $count = 0;
   } else {
      $sql = $query;
      $params = array_slice( func_get_args(), 2 );
    }
   $sql = trim( $sql );

   // If it is a simple query without LIMIT, add LIMIT offset,count to it
   if ( $count && ! preg_match( '~\bLIMIT\s+\d+(,\d+|\s+OFFSET\s+\d+)?\s*$~i', $sql ) ) {
      $sql .= $offset ? " LIMIT $offset,$count" : " LIMIT $count";
      $offset = 0;
   }
   $params[0] = $sql;

   db_connect();
   $sql = _db_prepare( $params );
   addiah_log_time( 'db', $sql );
   $res = mysql_query( $sql );
   if ( $res === false ) db_get_errors( $sql );

   // Skip to desired row
   if ( $offset ) while ( $offset-- > 0 ) if ( ! mysql_fetch_assoc( $res ) ) return false;
   $result = array();
   $row = mysql_fetch_assoc( $res );
   if ( empty( $row ) ) {
      // Don't border to get any result if empty

   } else if ( sizeof( $row ) <= 1 ) {
      // If one column, returns a one dimension array
      do {
         $result[] = reset( $row );
         if ( --$count === 0 ) break;
      } while ( $row = mysql_fetch_assoc( $res ) );

   } else if ( isset( $row['id'] ) && $row['id'] !== null && ! is_object( $row['id'] ) ) {
      // Has ID to map
      do {
         $id = $row['id'];
         if ( ! isset( $result[ $id ] ) )
            $result[ $id ] = $row;
         else
            $result[] = $row;
         if ( --$count === 0 ) break;
      } while ( $row = mysql_fetch_assoc( $res ) );

   } else {
      // Else just copy
      do {
         $result[] = $row;
         if ( --$count === 0 ) break;
      } while ( $row = mysql_fetch_assoc( $res ) );
   }

   mysql_free_result( $res );
   addiah_log_time( 'db', null, sizeof( $result ) . " rows" );
   return $result;
}

/**
 * Execute an SQL update or replace/insert.  Can also be used to execute other state-changing query.
 *
 * @param string $query Query to execute
 * @return mixed If an insert/replace with autoinc, return inserted id; if update, return affected rows; otherwise return true.
 * @throws InvalidArgumentException If query cannot be executed.
 */
function db_write( $query ) {
   db_connect();
   $query = trim( _db_prepare( func_get_args() ) );
   $res = mysql_query( $query );
   if ( $res === false ) db_get_errors( $query );
   // Get result
   $result = true;
   if ( preg_match( '~^(INSERT|REPLACE)\W~iS', $query) ) {
      $result = mysql_insert_id( $res );
   }
   else if ( preg_match( '~^(?:UPDATE|DELETE)\W~iS', $query ) ) {
      $result = mysql_affected_rows( $res );
   }
   if ( is_resource($res) ) mysql_free_result( $res );
   return $result;
}

/**
 * Get database connection.
 *
 * @return Resource Database connection resource, or null if not connected
 */
function db_get_connection() {
   global $addiah;
   return isset( $addiah['_db_conn'] ) ? $addiah['_db_conn'] : null;
}

/**
 * Execute an SQL query, resulted resource are NOT freed.
 * This is low level function; try to use db_read, db_read_all, and db_write instead.
 *
 * @param string $query Query to execute
 * @return mixed Result resource from sqlsrv_query
 * @throws InvalidArgumentException If query cannot be executed
 */
function db_query($query) {
   $res = _db_query($query);
   if ( $res === false ) db_get_errors( $query );
   return $res;
}

/**
 * Release a database result resource.
 *
 * @param Resource $res Resource to free
 */
function db_free( $res ) {
   if ( is_resource($res) ) mysql_free_result($res);
}

/**
 * Compose database specific functions.
 *  now: return expression to get current time
 *  today: expression for current date
 *  datediff: $param2 - $param1 (both default now) in unit of $param3 (default 'day', can be 'second','minute','hour','day','week','month','quarter','year')
 *  dateadd: $param1 (default now) + $param2 in unit of $param3 (default 'day', same as datediff)
 *
 * @param type $function Function to return
 * @param type $param1 Parameter 1
 * @param type $param2 Parameter 2
 * @param type $param3 Parameter 3
 * @return string
 */
function db_function( $function, $param1=null, $param2=null, $param3=null ) {
   switch ( strtolower( $function ) ) {

      case 'now' :
         return 'NOW()';

      case 'today' :
         return 'CURDATE()';

      case 'datediff' :
         if ( $param1 === null ) $param1 = 'NOW()';
            else if ( function_exists( 'db_quote' ) ) $param1 = db_value_quote( $param1 );
         if ( $param2 === null ) $param2 = 'NOW()';
            else if ( function_exists( 'db_quote' ) ) $param2 = db_value_quote( $param2 );
         if ( $param3 === null ) $param3 = 'DAY';
         return "TIMESTAMPDIFF($param3,$param1,$param2)";

      case 'dateadd' :
         if ( $param1 === null ) $param1 = 'SYSDATETIME()';
            else if ( function_exists( 'db_quote' ) ) $param1 = db_value_quote( $param1 );
         if ( function_exists( 'db_quote' ) ) $param2 = db_value_quote( $param2 );
         if ( $param3 === null ) $param3 = 'DAY';
         return "($param1 + INTERVAL $param2 $param3)";

      case 'limit' :
         if ( $param2 === null ) return "LIMIT $param1";
         else return "LIMIT $param1 OFFSET $param2";

      case 'offset' :
         if ( $param2 === null ) return "LIMIT 18446744073709551610 OFFSET $param1";
         else return "LIMIT $param2 OFFSET $param1";

      default:
         return "'[DB_MySQL] Invalid db_function'";
   }
};

/** Escape string. */
function db_escape($str) {
   if (function_exists('mysql_real_escape_string')) {
      // Connect if not yet connected
      db_connect();
      return mysql_real_escape_string($str, db_get_connection() );
   } else {
      return mysql_escape_string($str);
   }
}


/**
 * Start a transaction
 */
function db_begin() {
   _db_query("BEGIN");
}

/**
 * Finish a transaction
 */
function db_commit() {
   _db_query("COMMIT");
}

/**
 * Rollback a transcation
 */
function db_rollback() {
   _db_query("ROLLBACK");
}

/**
 * Get structural information of tables.
 *
 * Should contains all info from db engine. Guarentees:
 *
 * $result = [
 *   'table name' => [
 *      'name' => '...',
 *      'type' => 'table' or 'view',
 *      'fields' => [
 *         'field name' => [ 'name' => '...', 'type' => '..., 'null' => yes OR no ]
 *      ]
 *   ]
 * ]
 *
 * @param mixed $tables (Array of) tablename to get column info
 */
function db_get_tables( $tables = null ) {
   global $db_prefix;
   if ( !$tables || ! is_array( $tables ) ) {
      foreach ( db_read_all('SHOW TABLE STATUS') as $table ) {
         $table['type'] = $table['Engine'] ? 'table' : 'view';
         $tables[ $table['Name'] ] = $table;
      }
   } else
      foreach ($tables as $k => $v) $tables[$k]['name'] = $db_prefix.$v;

   $result = array();
   // Foreach table, get info
   foreach ($tables as $t => $tb) {
      // Initialise & copy table info
      $table = array( 'fields' => array() );
      foreach ($tb as $k => $v) $table[ strtolower($k) ] = $v;

      foreach ( db_read_all( "SHOW COLUMNS IN`$t`") as $field ) {
         $f = array( 'name' => $field['Field'] );
         foreach ( $field as $k => $v ) $f[ strtolower($k) ] = $v;
         $f['null'] = $f['null'] === 'YES';
         $table['fields'][$field['Field']] = $f;
      }
      $result[$t] = $table;
   }
   return $result;
}

/**
 * Get database error messages from pervious query.
 *
 * @param string $query If given, will instead raise an exception depending on debug mode.
 * @return array Array of error messages
 */
function db_get_errors( $query = null ) {
   if ( $query ) {
      if ( conf('debug') ) {
         $message = mysql_error() . " \n$query";
      } else {
         $message = "Error when running query. Please contact system administrator.";
      }
      throw new Exception( $message );
   }
   return array( mysql_error() );
}

?>
