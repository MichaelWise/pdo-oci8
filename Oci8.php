<?php
/**
 * PDO userspace driver proxying calls to PHP OCI8 driver
 */

namespace Intersvyaz\Pdo;

use OCICollection;
use PDO;
use PDOStatement;

/**
 * Oci8 class to mimic the interface of the PDO class
 * This class extends PDO but overrides all of its methods. It does this so
 * that instanceof checks and type-hinting of existing code will work
 * seamlessly.
 */
class Oci8 extends PDO
{
    const LOB_SQL = 0;
    const LOB_PL_SQL = 1;

    /**
     * Param for test
     * @var bool
     */
    public bool $test = false;

    /**
     * @var resource Database handler
     */
    private $dbh;
    /**
     * @var array Driver options
     */
    private array $options = [];
    /**
     * @var bool Whether currently in a transaction
     */
    private bool $inTransaction = false;

    /**
     * Creates a PDO instance representing a connection to a database
     *
     * @param string $dsn
     * @param string|null $username [optional]
     * @param string|null $password [optional]
     * @param array|null $options [optional]
     */
    public function __construct(string $dsn, ?string $username, ?string $password, ?array $options = [])
    {
        $dbName = $this->parseDsn($dsn, 'dbname');
        $charset = $this->parseDsn($dsn, 'charset', 'AL32UTF8');

        $this->connect($username, $password, $dbName, $charset, $options ?: []);

        // Save the options
        $this->options = $options;
    }

    /**
     * Prepares a statement for execution and returns a statement object
     *
     * @param string $query This must be a valid SQL statement for the
     *   target database server.
     * @param array|null $options [optional] This array holds one or more key=>value
     *   pairs to set attribute values for the PDOStatement object that this
     *   method returns.
     * @return Oci8Statement
     * @throws Oci8Exception
     */
    public function prepare(string $query, ?array $options = null): PDOStatement
    {
        // Get instance options
        if ($options == null) {
            $options = $this->options;
        }

        $sth = @oci_parse($this->dbh, $query);

        if (!$sth) {
            $e = oci_error($this->dbh);
            throw new Oci8Exception($e['message']);
        }

        if ($options === null) {
            $options = $this->options;
        }

        if (!is_array($options)) {
            $options = [];
        }

        return new Oci8Statement($sth, $this, $options);
    }

    /**
     * Initiates a transaction
     *
     * @return bool TRUE on success or FALSE on failure
     * @throws Oci8Exception
     */
    public function beginTransaction(): bool
    {
        if ($this->inTransaction()) {
            throw new Oci8Exception('There is already an active transaction');
        }

        $this->inTransaction = true;

        return true;
    }

    /**
     * Checks if inside a transaction
     *
     * @return bool TRUE if a transaction is currently active, and FALSE if not.
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Commits a transaction
     *
     * @return bool TRUE on success or FALSE on failure.
     * @throws Oci8Exception
     */
    public function commit(): bool
    {
        if (!$this->inTransaction()) {
            throw new Oci8Exception('There is no active transaction');
        }

        if (oci_commit($this->dbh)) {
            $this->inTransaction = false;

            return true;
        }

        return false;
    }

    /**
     * Rolls back a transaction
     *
     * @return bool TRUE on success or FALSE on failure.
     * @throws Oci8Exception
     */
    public function rollBack(): bool
    {
        if (!$this->inTransaction()) {
            throw new Oci8Exception('There is no active transaction');
        }

        if (oci_rollback($this->dbh)) {
            $this->inTransaction = false;

            return true;
        }

        return false;
    }

    /**
     * Sets an attribute on the database handle
     *
     * @param int $attribute
     * @param mixed $value
     * @return bool TRUE on success or FALSE on failure.
     */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->options[$attribute] = $value;

        return true;
    }

    /**
     * Executes an SQL statement and returns the number of affected rows
     *
     * @param string $statement The SQL statement to prepare and execute.
     * @return int The number of rows that were modified or deleted by the SQL
     *   statement you issued.
     */
    public function exec(string $statement): int
    {
        $stmt = $this->prepare($statement);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Executes an SQL statement, returning the results as a
     * Intersvyaz\Pdo\Oci8\Oci8Statement object
     *
     * @link https://php.net/manual/en/pdo.query.php
     *
     * @param string $statement <p>
     *                             The SQL statement to prepare and execute.
     *                             </p>
     *                             <p>
     *                             Data inside the query should be properly escaped.
     *                             </p>
     * @param int|null $mode The fetch mode must be one of the PDO::FETCH_* constants.
     * @param mixed $fetch_mode_args <p>
     *                                  Arguments of custom class constructor when the <i>mode</i>
     *                                  parameter is set to <b>PDO::FETCH_CLASS</b>.
     *                                  </p>
     * @see  PDOStatement::setFetchMode For a full description of the second and following parameters.
     * @return Oci8Statement
     */
    public function query(string $statement, ?int $mode = PDO::ATTR_DEFAULT_FETCH_MODE, mixed ...$fetch_mode_args): PDOStatement
    {
        $stmt = $this->prepare($statement);
        $stmt->execute();

        if ($mode) {
            $stmt->setFetchMode($mode, $fetch_mode_args);
        }

        return $stmt;
    }

    /**
     * Method not implemented
     * @param string|null $name Sequence name; no use in this context
     * @return mixed Last sequence number or 0 if sequence does not exist
     * @throws Oci8Exception
     */
    public function lastInsertId(string $name = null)
    {
        if (!$this->test) {
            throw new Oci8Exception("Method not implemented");
        }

        if (!$this->checkSequence($name)) {
            return 0;
        }

        $stmt = $this->query("SELECT $name.CURRVAL FROM DUAL", PDO::FETCH_COLUMN);

        return $stmt->fetch();
    }

    /**
     * Fetch the SQLSTATE associated with the last operation on the database
     * handle
     * While this returns an error code, it merely emulates the action. If
     * there are no errors, it returns the success SQLSTATE code (00000).
     * If there are errors, it returns HY000. See errorInfo() to retrieve
     * the actual Oracle error code and message.
     *
     * @return string
     */
    public function errorCode(): string
    {
        $error = $this->errorInfo();

        return $error[0];
    }

    /**
     * Returns extended error information for the last operation on the database
     * handle
     * The array consists of the following fields:
     *   0  SQLSTATE error code (a five characters alphanumeric identifier
     *      defined in the ANSI SQL standard).
     *   1  Driver-specific error code.
     *   2  Driver-specific error message.
     *
     * @return array Error information
     */
    public function errorInfo(): array
    {
        $e = oci_error($this->dbh);

        if (is_array($e)) {
            return [
                'HY000',
                $e['code'],
                $e['message']
            ];
        }

        return ['00000', null, null];
    }

    /**
     * Retrieve a database connection attribute
     *
     * @param int $attribute
     * @return mixed A successful call returns the value of the requested PDO
     *   attribute. An unsuccessful call returns null.
     */
    public function getAttribute(int $attribute): mixed
    {
        if ($attribute == PDO::ATTR_DRIVER_NAME) {
            return "oci8";
        }

        if (isset($this->options[$attribute])) {
            return $this->options[$attribute];
        }

        return null;
    }

    /**
     * Special non PDO function used to start cursors in the database
     * Remember to call oci_free_statement() on your cursor
     *
     * @access public
     * @return mixed New statement handle, or FALSE on error.
     */
    public function getNewCursor(): mixed
    {
        return oci_new_cursor($this->dbh);
    }

    /**
     * Special non PDO function used to start descriptor in the database
     * Remember to call oci_free_statement() on your cursor
     *
     * @access public
     * @param int $type One of OCI_DTYPE_FILE, OCI_DTYPE_LOB or OCI_DTYPE_ROWID.
     * @return mixed New LOB or FILE descriptor on success, FALSE on error.
     */
    public function getNewDescriptor(int $type = OCI_D_LOB): mixed
    {
        return oci_new_descriptor($this->dbh, $type);
    }

    /**
     * Special non PDO function used to close an open cursor in the database
     *
     * @access public
     * @param mixed $cursor A valid OCI statement identifier.
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function closeCursor(mixed $cursor): bool
    {
        return oci_free_statement($cursor);
    }

    /**
     * Special non PDO function
     * Allocates new collection object
     *
     * @param string $typeName Should be a valid named type (uppercase).
     * @param string $schema Should point to the scheme, where the named type was created.
     *  The name of the current user is the default value.
     * @return OCICollection
     */
    public function getNewCollection(string $typeName, string $schema): OCICollection
    {
        return oci_new_collection($this->dbh, $typeName, $schema);
    }

    /**
     * Places quotes around the input string
     *  If you are using this function to build SQL statements, you are strongly
     * recommended to use prepare() to prepare SQL statements with bound
     * parameters instead of using quote() to interpolate user input into an SQL
     * statement. Prepared statements with bound parameters are not only more
     * portable, more convenient, immune to SQL injection, but are often much
     * faster to execute than interpolated queries, as both the server and
     * client side can cache a compiled form of the query.
     *
     * @param string $string The string to be quoted.
     * @param int $paramType Provides a data type hint for drivers that have
     *   alternate quoting styles
     * @return string Returns a quoted string that is theoretically safe to pass
     *   into an SQL statement.
     * @todo Implement support for $paramType.
     */
    public function quote(string $string, int $paramType = PDO::PARAM_STR): string
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }

    /**
     * Special non PDO function to check if sequence exists
     *
     * @param string|null $name
     * @return bool
     */
    public function checkSequence(string $name = null): bool
    {
        if (!$name) {
            return false;
        }

        $sql = "SELECT count(*) FROM all_sequences WHERE sequence_name=upper('{$name}')";
        $stmt = $this->query($sql,PDO::FETCH_COLUMN);

        return $stmt->fetch();
    }

    /**
     * Parse DSN string and get $param value.
     * @param string $dsn
     * @param string $param
     * @param mixed|null $default
     * @return string|null
     */
    private function parseDsn(string $dsn, string $param, mixed $default = null): ?string
    {
        if (preg_match('/' . $param . '=(?<param>[^;]+)/', $dsn, $mathes)) {
            return $mathes['param'];
        }

        return $default;
    }

    /**
     * Connect to database
     * @param string $username
     * @param string $password
     * @param string $dbName
     * @param string $charset
     * @param array $options
     */
    private function connect(string $username, string $password, string $dbName, string $charset, array $options = [])
    {
        if (array_key_exists(PDO::ATTR_PERSISTENT, $options) && $options[PDO::ATTR_PERSISTENT]) {
            $this->dbh = @oci_pconnect($username, $password, $dbName, $charset);
        } else {
            $this->dbh = @oci_connect($username, $password, $dbName, $charset);
        }

        if (!$this->dbh) {
            $e = oci_error();
            throw new Oci8Exception($e['message'], $e['code']);
        }
    }

    /**
     * Return available drivers
     * Will insert the OCI driver on the list, if not exist.
     *
     * @return array with drivers
     */
    public static function getAvailableDrivers(): array
    {
        $drivers = PDO::getAvailableDrivers();
        if (!in_array('oci', $drivers)) {
            $drivers[] = 'oci';
        }

        return $drivers;
    }

    /**
     * Set the client identifier.
     *
     * @param $identifier
     * @return bool
     */
    public function setClientIdentifier($identifier): bool
    {
        if (!$this->dbh) {
            return false;
        }

        return oci_set_client_identifier($this->dbh, $identifier);
    }

    /**
     * Close the connection.
     *
     * @link https://www.oracle.com/technetwork/topics/php/php-scalability-ha-twp-128842.pdf oci_close should be called
     *       if the connection is pooled
     */
    public function close()
    {
        if ($this->dbh) {
            oci_close($this->dbh);
            $this->dbh = null;
        }
    }
}
