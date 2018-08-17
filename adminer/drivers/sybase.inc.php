<?php
$drivers["sybase5"] = "Sybase";
$drivers["sybase"] = "Sybase PDO";

if (isset($_GET["sybase"]) || isset($_GET["sybase5"])) {
	$possible_drivers = array("sybase5", "sybase");
	define("DRIVER", (isset($_GET["sybase5"]) ? "sybase5" : "sybase"));

	if (!function_exists('ci_remove_invisible_characters')) {
		function ci_remove_invisible_characters($str, $url_encoded = TRUE) {
			$non_displayables = array();
			if ($url_encoded) {
				$non_displayables[] = '/%0[0-8bcef]/';  // url encoded 00-08, 11, 12, 14, 15
				$non_displayables[] = '/%1[0-9a-f]/'; // url encoded 16-31
			}
			$non_displayables[] = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/S'; // 00-08, 11, 12, 14-31, 127
			do {
				$str = preg_replace($non_displayables, '', $str, -1, $count);
			} while ($count);
			return $str;
		}
	}

	if (isset($_GET["sybase5"]) && function_exists('sybase_connect')) {
		class Min_Sybase {
			var $extension = "sybase5", $server_info, $affected_rows, $errno, $error, $_link;

			var $_path = null;

			private function sybase_set_env($database, $host, $port, $version = '5.0') {
				if ($this->_path) return;

				$path = tempnam(sys_get_temp_dir(), 'ftds');
				// $log = '/tmp/ftds.log';
				register_shutdown_function(function() use($path) {
					unlink($path);
				});

				$file = fopen($path, "w");
				fwrite($file, "\n");
				fwrite($file, "[global]" . "\n");
				fwrite($file, "\t". "text size = 64512" . "\n");
				// fwrite($file, "\t". "dump file = {$log}\n");
				isset($database) && fwrite($file, "[{$database}]" . "\n");
				isset($host)     && fwrite($file, "\t". "host = {$host}" . "\n");
				isset($port)     && fwrite($file, "\t". "port = {$port}" . "\n");
				isset($version)  && fwrite($file, "\t". "tds version = {$version}" . "\n");
				fwrite($file, "\n");
				fclose($file);

				putenv("FREETDSCONF={$path}");
				$this->_path = $path;
			}

			function connect($server, $username, $password, $database, $charset = null) {
				list($host, $port) = explode(":", $server, 2); // part after : is used for port or socket

				$this->sybase_set_env($database, $host, $port);
				$this->_link = is_null($charset)
					? @sybase_connect($database, $username, $password)
					: @sybase_connect($database, $username, $password, $charset);

				if ($this->_link) {
					$this->server_info = sybase_get_last_message();
				} else {
					$this->error = sybase_get_last_message();
				}
				return (bool) $this->_link;
			}

			function select_db($database) {
				return true;
			}

			function query($query, $unbuffered = false) {
				$result = @($unbuffered
					? sybase_unbuffered_query($query, $this->_link)
					: sybase_query($query, $this->_link)); // @ - mute mysql.trace_mode
				$this->error = "";
				if (!$result) {
					$this->error = sybase_get_last_message();
					return false;
				}
				if ($result === true) {
					$this->affected_rows = sybase_affected_rows($this->_link);
					$this->info = sybase_get_last_message();
					return true;
				}
				return new Min_Result($result);
			}

			function quote($string) {
				return "'" . str_replace("'", "''", ci_remove_invisible_characters($string)) . "'";
			}

			function store_result() {
				return $this->_result;
			}

			function result($query, $field = null) {
				$result = $this->query($query);
				if (!$result || !$result->num_rows) {
					return false;
				}
				return is_null($field)
					? sybase_result($result->_result, 0, 0)
					: sybase_result($result->_result, 0, $field);
			}
		}

		class Min_Result {
			var $_result, $_offset = 0, $num_rows;

			function __construct($result) {
				$this->num_rows = sybase_num_rows($result);
				$this->_result = $result;
			}

			function fetch_assoc() {
				return sybase_fetch_assoc($this->_result);
			}

			function fetch_row() {
				return sybase_fetch_row($this->_result);
			}

			function fetch_field() {
				$return = sybase_fetch_field($this->_result, $this->_offset++); // offset required under certain conditions
				// $return->orgtable = $return->table;
				// $return->orgname = $return->name;
				// $return->charsetnr = ($return->blob ? 63 : 0);
				return $return;
			}

			function __desctruct() {
				sybase_free_result($this->_result);
			}
		}
	} elseif (extension_loaded("pdo_dblib")) {
		class Min_Sybase extends Min_PDO {
			var $extension = "sybase";

			var $_path = null;

			private function sybase_set_env($database, $host, $port, $version = '5.0') {
				if ($this->_path) return;

				$path = tempnam(sys_get_temp_dir(), 'ftds');
				// $log = '/tmp/ftds.log';
				register_shutdown_function(function() use($path) {
					unlink($path);
				});

				$file = fopen($path, "w");
				fwrite($file, "\n");
				fwrite($file, "[global]" . "\n");
				fwrite($file, "\t". "text size = 64512" . "\n");
				// fwrite($file, "\t". "dump file = {$log}\n");
				isset($database) && fwrite($file, "[{$database}]" . "\n");
				isset($host)     && fwrite($file, "\t". "host = {$host}" . "\n");
				isset($port)     && fwrite($file, "\t". "port = {$port}" . "\n");
				isset($version)  && fwrite($file, "\t". "tds version = {$version}" . "\n");
				fwrite($file, "\n");
				fclose($file);

				putenv("FREETDSCONF={$path}");
				$this->_path = $path;
			}

			function connect($server, $username, $password, $database, $charset = null) {
				list($host, $port) = explode(":", $server, 2); // part after : is used for port or socket

				$this->sybase_set_env($database, $host, $port);
				$this->dsn(
					"dblib:host={$database}",
					$username,
					$password
				);

				if (!is_null($charset)) {
					$this->set_charset($charset);
				}

				return true;
			}

			function set_charset($charset) {
				$this->query("SET NAMES $charset"); // charset in DSN is ignored before PHP 5.3.6
			}
		}
	}

	if (class_exists("Min_Sybase")) {
		class Min_DB extends Min_Sybase {

			function multi_query($query) {
				return $this->_result = $this->query($query);
			}

			function next_result() {
				return false;
			}
		}
	}

	class Min_Driver extends Min_SQL {
	}



	function idf_escape($idf) {
		return str_replace("'", "''", $idf);
	}

	function table($idf) {
		return idf_escape($idf);
	}

	function connect() {
		if (!isset($_GET['db']) || !$_GET['db']) {
			return 'must fill database name';
		}

		global $adminer;
		$credentials = $adminer->credentials();
		$database = $_GET['db'];
		$charset = null;
		$connection = new Min_DB;
		if ($connection->connect($credentials[0], $credentials[1], $credentials[2], $database, $charset)) {
			return $connection;
		}

		return $connection->error;
	}

	function get_databases() {
		return array();
	}

	function limit($query, $where, $limit, $offset = 0, $separator = " ") {
		$offset += $limit;
		$limitQuery = "";
		if ($offset <= 32767) {
			$limitQuery = " TOP {$offset} ";
		}
		return ($limit !== null ? "{$limitQuery}{$query}{$separator}" : $query) . $where;
	}

	function limit1($table, $query, $where, $separator = "\n") {
		return limit($query, $where, 1, 0, $separator);
	}

	function db_collation($db, $collations) {
		global $connection;
		return $connection->result("SELECT collation_label from SYS.SYSCOLLATION");
		/*
		global $connection;
		return $connection->result("PRAGMA encoding"); // there is no database list so $db == DB
		*/
	}

	function engines() {
		return array();
	}

	function logged_user() {
		return get_current_user(); // should return effective user
	}

	function tables_list() {
		return get_key_vals("
			SELECT
			(creator + '.' + tname),
			tabletype

			FROM SYS.SYSCATALOG

			WHERE tabletype IN ('TABLE', 'VIEW')
		");
	}

	function count_tables($databases) {
		return array();
	}

	/** Get table status
	* @param string
	* @param bool return only "Name", "Engine" and "Comment" fields
	* @return array array($name => array("Name" => , "Engine" => , "Comment" => , "Oid" => , "Rows" => , "Collation" => , "Auto_increment" => , "Data_length" => , "Index_length" => , "Data_free" => )) or only inner array with $name
	*/
	function table_status($name = "") {
		global $connection;
		$return = array();

		$whereName = $name != "" ? "AND Name = {$name}" : "";

		foreach (get_rows("
			SELECT
			(SYSUSERS.name + '.' + SYSOBJECTS.name) AS Name,
			SYSTABLE.table_type as Engine,
			SYSTABLE.remarks as 'Comment',
			'' as Oid,
			SYSTABLE.COUNT as Rows,
			'' as Collation,
			'' as Auto_increment,
			'' as Data_length,
			'' as Index_length,
			'' as Data_free

			FROM
			dbo.SYSOBJECTS AS SYSOBJECTS
				LEFT JOIN dbo.SYSUSERS AS SYSUSERS
					ON SYSOBJECTS.uid = SYSUSERS.uid
				LEFT JOIN SYS.SYSTABLE AS SYSTABLE
					ON SYSOBJECTS.id - 100000 = SYSTABLE.table_id

			WHERE SYSOBJECTS.type IN ('U', 'V')
			{$whereName}

			ORDER BY
			SYSOBJECTS.type,
			SYSOBJECTS.id
		") as $row) {
			// $row['Engine'] = $row['Engine'] == 'BASE' ? 'TABLE': $row['Engine'];

			if ($name != "") {
				return $row;
			}
			$return[$row['Name']] = $row;
		}

		return $return;
	}

	function is_view($table_status) {
		return $table_status["Engine"] == "VIEW";
	}

	function fk_support($table_status) {
		return false;
	}

	/** Get information about fields
	* @param string
	* @return array array($name => array("field" => , "full_type" => , "type" => , "length" => , "unsigned" => , "default" => , "null" => , "auto_increment" => , "on_update" => , "collation" => , "privileges" => , "comment" => , "primary" => ))
	*/
	function fields($table) {
		$return = array();

		foreach (get_rows("
			SELECT
			cname AS field,
			coltype AS full_type,
			coltype AS type,
			length,
			coltype AS 'unsigned',
			default_value AS 'default',
			nulls AS 'null',
			'' AS auto_increment,
			'' AS on_update,
			'' AS collation,
			'' AS 'privileges',
			remarks AS 'comment',
			in_primary_key AS 'primary'

			FROM
			SYS.SYSCOLUMNS AS SYSCOLUMNS

			WHERE (SYSCOLUMNS.creator + '.' + SYSCOLUMNS.tname) = '{$table}'
		") as $row) {
			$row['unsigned'] = preg_replace('/(unsigned)?.*/', '\1', $row['unsigned']);
			$row['type'] = preg_replace('/unsigned /', '', $row['type']);
			$row['type'] = preg_replace('/long /', '', $row['type']);
			$row['null'] = $row['null'] == 'Y';
			$row['privileges'] = array_merge(array('select' => 1), array($row['privileges']));
			$row['primary'] = $row['primary'] == 'Y';
			$return[$row['field']] = $row;
		}

		return $return;
	}

	/** Get table indexes
	* @param string
	* @param string Min_DB to use
	* @return array array($key_name => array("type" => , "columns" => array(), "lengths" => array(), "descs" => array()))
	*/
	/** Get table indexes
	array(
	"type" => ,
	"columns" => array(),
	"lengths" => array(),
	"descs" => array()))
	*/
	function indexes($table, $connection2 = null) {
		$return = array();

		foreach (get_rows("
			SELECT
			SYSINDEXES.status AS type,
			SYSINDEXES.name AS columns,
			SYSINDEXES.keysl AS lengths,
			'' AS descs,
			SYSINDEXES.name AS key_name

			FROM
			dbo.SYSINDEXES AS SYSINDEXES
				LEFT JOIN dbo.SYSOBJECTS AS SYSOBJECTS
					ON SYSINDEXES.id = SYSOBJECTS.id
				LEFT JOIN dbo.SYSUSERS AS SYSUSERS
					ON SYSOBJECTS.uid = SYSUSERS.uid

			WHERE SYSUSERS.name + '.' + SYSOBJECTS.name = '{$table}'
			AND SYSINDEXES.indid > 0
			AND SYSINDEXES.status & 2 = 2
		") as $row) {
			$return[$row['key_name']] = $row;
			unset($return[$row['key_name']]['key_name']);
		}

		// var_dump($return);
		// die;

		return $return;
	}

	function foreign_keys($table) {
		$return = array();
		// die;
		return $return;
	}

	function view($name) {
		// SYS.SYSVIEWS
		$return = array();

		return $return;
	}

	function collations() {
		/*
		return (isset($_GET["create"]) ? get_vals("PRAGMA collation_list", 1) : array());
		*/
	}

	function information_schema($db) {
		return false;
	}

	function error() {
		global $connection;
		return h($connection->error);
	}

	function create_database($db, $collation) {
		return false;
	}

	function drop_databases($databases) {
		return false;
	}

	function rename_database($name, $collation) {
		return false;
	}

	function auto_increment() {
		return "";
		// return " PRIMARY KEY" . (DRIVER == "sqlite" ? " AUTOINCREMENT" : "");
	}

	function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
		return false;

		$use_all_fields = ($table == "" || $foreign);
		foreach ($fields as $field) {
			if ($field[0] != "" || !$field[1] || $field[2]) {
				$use_all_fields = true;
				break;
			}
		}
		$alter = array();
		$originals = array();
		foreach ($fields as $field) {
			if ($field[1]) {
				$alter[] = ($use_all_fields ? $field[1] : "ADD " . implode($field[1]));
				if ($field[0] != "") {
					$originals[$field[0]] = $field[1][0];
				}
			}
		}
		if (!$use_all_fields) {
			foreach ($alter as $val) {
				if (!queries("ALTER TABLE " . table($table) . " $val")) {
					return false;
				}
			}
			if ($table != $name && !queries("ALTER TABLE " . table($table) . " RENAME TO " . table($name))) {
				return false;
			}
		} elseif (!recreate_table($table, $name, $alter, $originals, $foreign)) {
			return false;
		}
		if ($auto_increment) {
			queries("UPDATE sqlite_sequence SET seq = $auto_increment WHERE name = " . q($name)); // ignores error
		}
		return true;
	}

	function recreate_table($table, $name, $fields, $originals, $foreign, $indexes = array()) {
		return false;

		if ($table != "") {
			if (!$fields) {
				foreach (fields($table) as $key => $field) {
					if ($indexes) {
						$field["auto_increment"] = 0;
					}
					$fields[] = process_field($field, $field);
					$originals[$key] = idf_escape($key);
				}
			}
			$primary_key = false;
			foreach ($fields as $field) {
				if ($field[6]) {
					$primary_key = true;
				}
			}
			$drop_indexes = array();
			foreach ($indexes as $key => $val) {
				if ($val[2] == "DROP") {
					$drop_indexes[$val[1]] = true;
					unset($indexes[$key]);
				}
			}
			foreach (indexes($table) as $key_name => $index) {
				$columns = array();
				foreach ($index["columns"] as $key => $column) {
					if (!$originals[$column]) {
						continue 2;
					}
					$columns[] = $originals[$column] . ($index["descs"][$key] ? " DESC" : "");
				}
				if (!$drop_indexes[$key_name]) {
					if ($index["type"] != "PRIMARY" || !$primary_key) {
						$indexes[] = array($index["type"], $key_name, $columns);
					}
				}
			}
			foreach ($indexes as $key => $val) {
				if ($val[0] == "PRIMARY") {
					unset($indexes[$key]);
					$foreign[] = "  PRIMARY KEY (" . implode(", ", $val[2]) . ")";
				}
			}
			foreach (foreign_keys($table) as $key_name => $foreign_key) {
				foreach ($foreign_key["source"] as $key => $column) {
					if (!$originals[$column]) {
						continue 2;
					}
					$foreign_key["source"][$key] = idf_unescape($originals[$column]);
				}
				if (!isset($foreign[" $key_name"])) {
					$foreign[] = " " . format_foreign_key($foreign_key);
				}
			}
			queries("BEGIN");
		}
		foreach ($fields as $key => $field) {
			$fields[$key] = "  " . implode($field);
		}
		$fields = array_merge($fields, array_filter($foreign));
		if (!queries("CREATE TABLE " . table($table != "" ? "adminer_$name" : $name) . " (\n" . implode(",\n", $fields) . "\n)")) {
			// implicit ROLLBACK to not overwrite $connection->error
			return false;
		}
		if ($table != "") {
			if ($originals && !queries("INSERT INTO " . table("adminer_$name") . " (" . implode(", ", $originals) . ") SELECT " . implode(", ", array_map('idf_escape', array_keys($originals))) . " FROM " . table($table))) {
				return false;
			}
			$triggers = array();
			foreach (triggers($table) as $trigger_name => $timing_event) {
				$trigger = trigger($trigger_name);
				$triggers[] = "CREATE TRIGGER " . idf_escape($trigger_name) . " " . implode(" ", $timing_event) . " ON " . table($name) . "\n$trigger[Statement]";
			}
			if (!queries("DROP TABLE " . table($table))) { // drop before creating indexes and triggers to allow using old names
				return false;
			}
			queries("ALTER TABLE " . table("adminer_$name") . " RENAME TO " . table($name));
			if (!alter_indexes($name, $indexes)) {
				return false;
			}
			foreach ($triggers as $trigger) {
				if (!queries($trigger)) {
					return false;
				}
			}
			queries("COMMIT");
		}
		return true;
	}

	function index_sql($table, $type, $name, $columns) {
		return "";

		return "CREATE $type " . ($type != "INDEX" ? "INDEX " : "")
			. idf_escape($name != "" ? $name : uniqid($table . "_"))
			. " ON " . table($table)
			. " $columns"
		;
	}

	function alter_indexes($table, $alter) {
		return false;

		foreach ($alter as $primary) {
			if ($primary[0] == "PRIMARY") {
				return recreate_table($table, $table, array(), array(), array(), $alter);
			}
		}
		foreach (array_reverse($alter) as $val) {
			if (!queries($val[2] == "DROP"
				? "DROP INDEX " . idf_escape($val[1])
				: index_sql($table, $val[0], $val[1], "(" . implode(", ", $val[2]) . ")")
			)) {
				return false;
			}
		}
		return true;
	}

	function truncate_tables($tables) {
		return false;

		return apply_queries("DELETE FROM", $tables);
	}

	function drop_views($views) {
		return false;

		return apply_queries("DROP VIEW", $views);
	}

	function drop_tables($tables) {
		return false;

		return apply_queries("DROP TABLE", $tables);
	}

	function move_tables($tables, $views, $target) {
		return false;
	}

	/** Copy tables to other schema
	* @param array
	* @param array
	* @param string
	* @return bool
	*/
	function copy_tables($tables, $views, $target) {
		return false;
	}

	function trigger($name) {
		return array();

		global $connection;
		if ($name == "") {
			return array("Statement" => "BEGIN\n\t;\nEND");
		}
		$idf = '(?:[^`"\s]+|`[^`]*`|"[^"]*")+';
		$trigger_options = trigger_options();
		preg_match(
			"~^CREATE\\s+TRIGGER\\s*$idf\\s*(" . implode("|", $trigger_options["Timing"]) . ")\\s+([a-z]+)(?:\\s+OF\\s+($idf))?\\s+ON\\s*$idf\\s*(?:FOR\\s+EACH\\s+ROW\\s)?(.*)~is",
			$connection->result("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = " . q($name)),
			$match
		);
		$of = $match[3];
		return array(
			"Timing" => strtoupper($match[1]),
			"Event" => strtoupper($match[2]) . ($of ? " OF" : ""),
			"Of" => ($of[0] == '`' || $of[0] == '"' ? idf_unescape($of) : $of),
			"Trigger" => $name,
			"Statement" => $match[4],
		);
	}

	function triggers($table) {
		return array();

		$return = array();
		$trigger_options = trigger_options();
		foreach (get_rows("SELECT * FROM sqlite_master WHERE type = 'trigger' AND tbl_name = " . q($table)) as $row) {
			preg_match('~^CREATE\s+TRIGGER\s*(?:[^`"\s]+|`[^`]*`|"[^"]*")+\s*(' . implode("|", $trigger_options["Timing"]) . ')\s*(.*)\s+ON\b~iU', $row["sql"], $match);
			$return[$row["name"]] = array($match[1], $match[2]);
		}
		return $return;
	}

	function trigger_options() {
		return array();

		return array(
			"Timing" => array("BEFORE", "AFTER", "INSTEAD OF"),
			"Event" => array("INSERT", "UPDATE", "UPDATE OF", "DELETE"),
			"Type" => array("FOR EACH ROW"),
		);
	}

	function routine($name, $type) {
		return array();
	}

	function routines() {
		return array();
	}

	function routine_languages() {
		return array(); // "SQL" not required
	}

	function routine_id($name, $row) {
		return idf_escape($name);
	}

	function begin() {
		return false;

		return queries("BEGIN");
	}

	function last_id() {
		global $connection;
		return $connection->result("SELECT @@identity AS lastId");
	}

	function explain($connection, $query) {
		$connection->query("SET showplan on");
		$connection->query("SET noexec");
		return $connection->query("$query");
	}

	function found_rows($table_status, $where) {
		return null;
	}

	function types() {
		// select * from sys.SYSUSERTYPE
		return array();
	}

	function schemas() {
		return array();
	}

	function get_schema() {
		return "";
	}

	function set_schema($scheme) {
		return true;
	}

	function create_sql($table, $auto_increment, $style) {
		$return = "CREATE TABLE " . table($table) . " ";
		foreach (fields($table) as $k1 => $v1) {
			$return .= "\n  " . q($v1['field']) . " {$v1['type']}({$v1['length']})";
			$return .= $v1['null'] ? " NULL" : " NOT NULL";
			$return .= $v1['default'] ? " DEFAULT {$v1['default']}" : "";
		}

		return $return;
	}

	function truncate_sql($table) {
		return "";

		return "TRUNCATE TABLE " . table($table);
	}

	function use_sql($database) {
	}

	function trigger_sql($table) {
		return "";

		return implode(get_vals("SELECT sql || ';;\n' FROM sqlite_master WHERE type = 'trigger' AND tbl_name = " . q($table)));
	}

	function show_variables() {
		return "";

		global $connection;
		$return = array();
		foreach (array("auto_vacuum", "cache_size", "count_changes", "default_cache_size", "empty_result_callbacks", "encoding", "foreign_keys", "full_column_names", "fullfsync", "journal_mode", "journal_size_limit", "legacy_file_format", "locking_mode", "page_size", "max_page_count", "read_uncommitted", "recursive_triggers", "reverse_unordered_selects", "secure_delete", "short_column_names", "synchronous", "temp_store", "temp_store_directory", "schema_version", "integrity_check", "quick_check") as $key) {
			$return[$key] = $connection->result("PRAGMA $key");
		}
		return $return;
	}

	function process_list() {
		return array();
	}

	function show_status() {
		return "";

		$return = array();
		foreach (get_vals("PRAGMA compile_options") as $option) {
			list($key, $val) = explode("=", $option, 2);
			$return[$key] = $val;
		}
		return $return;
	}

	function convert_field($field) {
		if (preg_match("~timestamp|date~", $field["type"])) {
			return "date(" . idf_escape($field["field"]) . ")";
		} elseif (preg_match("~timestamp|datetime~", $field["type"])) {
			return "datetime(" . idf_escape($field["field"]) . ")";
		}
	}

	function unconvert_field($field, $return) {
		return $return;
	}

	function support($feature) {
		// select * from sys.SYSCAPABILITYNAME
		return preg_match('~^(columns|database|dump|sql|table|view)$~', $feature);
		return preg_match('~^(columns|database|dump|indexes|sql|status|table|view)$~', $feature);
	}

	function kill_process($val) {
		return false;
	}

	function connection_id() {
		return false;
	}

	function max_connections() {
		return 1;
	}

	$jush = "sybase";
	// $jush = "sqlite";
	$types = array();
	foreach (array(
		lang('Numbers') => array("tinyint" => 3, "smallint" => 5, "int" => 10, "integer" => 10, "bigint" => 20, "decimal" => 66, "numeric" => 66, "float" => 12, "real" => 12, "double" => 21, "money" => 21, "smallmoney" => 12),
		lang('Date and time') => array("date" => 10, "datetime" => 19, "timestamp" => 19, "time" => 12, "smalldatetime" => 16),
		lang('Strings') => array("char" => 255, "varchar" => 65535, "text" => 2147483647, "nchar" => 255, "unichar" => 255, "nvarchar" => 65535, "univarchar" => 65535, "unitext" => 2147483647),
		lang('Binary') => array("bit" => 1, "binary" => 255, "varbinary" => 65535, "image" => 2147483647),
	) as $key => $val) {
		$types += $val;
		$structured_types[$key] = array_keys($val);
	}

	$unsigned = array("unsigned");
	$operators = array("=", "<", ">", "<=", ">=", "!=", "LIKE", "LIKE %%", "IN", "IS NULL", "NOT LIKE", "NOT IN", "IS NOT NULL");
	$functions = array("cast", "convert", "date", "datetime", "ceil", "floor", "round", "char_length", "lower", "upper");
	$grouping = array("avg", "count", "count distinct", "group_concat", "max", "min", "sum");
	$edit_functions = array(
		array(
			"date|time" => "now",
		), array(
			number_type() => "+/-",
			"date" => "+ interval/- interval",
			"time" => "addtime/subtime",
			"char|text" => "concat",
		)
	);
}
