<?php

namespace Intersvyaz\Pdo;

use OCILob;
use PDO;
use PDOStatement;

/**
 * Oci8 Statement class to mimic the interface of the PDOStatement class
 * This class extends PDOStatement but overrides all of its methods. It does
 * this so that instanceof check and type-hinting of existing code will work
 * seamlessly.
 */
class Oci8Statement extends PDOStatement
{

    /**
     * @var resource Statement handler
     */
    private $sth;
    /**
     * @var Oci8 PDO Oci8 driver
     */
    private Oci8 $connection;
    /**
     * @var bool flag to convert LOB to string or not
     */
    private bool $returnLobs = true;
    /**
     * @var array Statement options
     */
    private array $options = [];
    /**
     * @var int Fetch mode selected via setFetchMode()
     */
    private int $fetchStyle = PDO::ATTR_DEFAULT_FETCH_MODE;
    /**
     * @var int Column number for PDO::FETCH_COLUMN fetch mode
     */
    private int $fetchColno = 0;
    /**
     * @var string Class name for PDO::FETCH_CLASS fetch mode
     */
    private string $fetchClassName = '\stdClass';
    /**
     * @var array Constructor arguments for PDO::FETCH_CLASS
     */
    private array $fetchCtorargs = [];
    /**
     * @var ?object Object reference for PDO::FETCH_INTO fetch mode
     */
    private ?object $fetchIntoObject;
    /**
     * @var OCILob[] Lob object, when need lob->save after oci_execute.
     */
    private array $saveLobs = [];
    /**
     * @var OCILob[] Lob object, when need lob->write after oci_bind_by_name.
     */
    private array $writeLobs = [];
    /**
     * @var array Array of param value, which binded in bindParam as lob.
     */
    private array $lobsValue = [];

    /**
     * Constructor
     *
     * @param resource $sth Statement handle created with oci_parse()
     * @param Oci8 $connection The Pdo_Oci8 object for this statement
     * @param array $options Options for the statement handle
     * @throws Oci8Exception
     */
    public function __construct($sth, Oci8 $connection, array $options = [])
    {
        if (strtolower(get_resource_type($sth)) !== 'oci8 statement') {
            throw new Oci8Exception(
                'Resource expected of type oci8 statement; '
                . (string)get_resource_type($sth) . ' received instead');
        }

        $this->sth = $sth;
        $this->connection = $connection;
        $this->options = $options;
    }

    /**
     * Executes a prepared statement
     *
     * @param array|null $inputParams An array of values with as many elements as
     *   there are bound parameters in the SQL statement being executed.
     * @return bool TRUE on success or FALSE on failure
     * @throws Oci8Exception
     */
    public function execute(array $inputParams = null): bool
    {
        $mode = OCI_COMMIT_ON_SUCCESS;

        $lobTransaction = false;
        // Begin transaction, if lobs need save, but transaction was not started before.
        if ((count($this->saveLobs) > 0 || count($this->writeLobs) > 0) && !$this->connection->inTransaction()) {
            $this->connection->beginTransaction();
            $lobTransaction = true;
        }

        if ($this->connection->inTransaction()) {
            $mode = OCI_DEFAULT;
        }

        // Set up bound parameters, if passed in
        if (is_array($inputParams)) {
            foreach ($inputParams as $key => $value) {
                $this->bindParam($key, $inputParams[$key]);
            }
        }

        if (count($this->writeLobs) > 0) {
            foreach ($this->writeLobs as $lobName => $lob) {
                $type = $lob['type'] == OCI_B_BLOB ? OCI_TEMP_BLOB : OCI_TEMP_CLOB;
                $lob['object']->writetemporary($this->lobsValue[$lobName], $type);
            }
        }

        $result = @oci_execute($this->sth, $mode);

        if ($result && count($this->saveLobs) > 0) {
            foreach ($this->saveLobs as $lobName => $object) {
                $object->save($this->lobsValue[$lobName]);
            }
        }

        if ($result != true) {
            $e = oci_error($this->sth);
            throw new Oci8Exception($e['message'], $e['code']);
        }

        if ($lobTransaction) {
            return $this->connection->commit();
        }

        return $result;
    }

    /**
     * Fetches the next row from a result set
     *
     * @param int|null $fetchStyle Controls how the next row will be returned to
     *   the caller. This value must be one of the PDO::FETCH_* constants,
     *   defaulting to value of PDO::ATTR_DEFAULT_FETCH_MODE (which defaults to
     *   PDO::FETCH_BOTH).
     * @param int $cursorOrientation For a PDOStatement object representing a
     *   scrollable cursor, this value determines which row will be returned to
     *   the caller. This value must be one of the PDO::FETCH_ORI_* constants,
     *  defaulting to PDO::FETCH_ORI_NEXT. To request a scrollable cursor for
     *   your PDOStatement object, you must set the PDO::ATTR_CURSOR attribute
     *   to PDO::CURSOR_SCROLL when you prepare the SQL statement with
     *   PDO::prepare.
     * @param int $cursorOffset [optional]
     * @return mixed The return value of this function on success depends on the
     *   fetch type. In all cases, FALSE is returned on failure.
     * @todo Implement cursorOrientation and cursorOffset
     */
    public function fetch(?int $fetchStyle = null, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $fetchStyle = $fetchStyle ?: $this->fetchStyle;

        $toLowercase = ($this->getAttribute(PDO::ATTR_CASE) === PDO::CASE_LOWER);

        // Determine the fetch mode
        switch ($fetchStyle) {
            case PDO::FETCH_BOTH:
                return $this->fetchArray(OCI_BOTH + OCI_RETURN_NULLS, $toLowercase);

            case PDO::FETCH_ASSOC:
                return $this->fetchArray(OCI_ASSOC + OCI_RETURN_NULLS, $toLowercase);

            case PDO::FETCH_NUM:
                return $this->fetchArray(OCI_NUM + OCI_RETURN_NULLS, false);

            case PDO::FETCH_COLUMN:
                return $this->fetchColumn((int)$this->fetchColno);

            case PDO::FETCH_INTO:
                $rs = $this->fetchArray(OCI_ASSOC + OCI_RETURN_NULLS, $toLowercase);
                if (is_object($this->fetchIntoObject)) {
                    return $this->populateObject($this->fetchIntoObject, $rs);
                } else {
                    return false;
                }

            case PDO::FETCH_OBJ:
            case PDO::FETCH_CLASS:
            case PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE:
                $className = ($fetchStyle === PDO::FETCH_OBJ) ? '\stdClass' : $this->fetchClassName;
                $ctorargs = ($fetchStyle === PDO::FETCH_OBJ) ? [] : $this->fetchCtorargs;

                return $this->fetchObject($className, $ctorargs);
        }

        return false;
    }

    /**
     * Set the default fetch mode for this statement
     *
     * @param int $mode <p>
     *                     The fetch mode must be one of the PDO::FETCH_* constants.
     *                     </p>
     * @param null $className
     * @param ?string ...$params <p> Constructor arguments. </p>
     * @return bool <b>TRUE</b> on success or <b>FALSE</b> on failure.
     * @throws Oci8Exception
     */
    public function setFetchMode($mode, $className = null, ...$params): bool
    {
        $modeArg = $params;

        $this->fetchStyle = $mode;
        $this->fetchClassName = '\stdClass';
        $this->fetchCtorargs = [];
        $this->fetchColno = 0;
        $this->fetchIntoObject = null;

        // See which fetch mode we have
        switch ($mode) {
            case PDO::FETCH_CLASS:
            case PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE:
                if ($modeArg) {
                    $this->fetchClassName = $modeArg;
                }
                break;
            case PDO::FETCH_INTO:
                if (!is_object($modeArg)) {
                    throw new Oci8Exception('$modeArg must be instance of an object');
                }
                $this->fetchIntoObject = $modeArg;
                break;
            case PDO::FETCH_COLUMN:
                $this->fetchColno = (int)$modeArg;
                break;
        }

        return true;
    }

    /**
     * Returns a single column from the next row of a result set
     *
     * @param int|null $colNumber 0-indexed number of the column you wish to retrieve
     *   from the row. If no value is supplied, it fetches the first column.
     * @return string Returns a single column in the next row of a result set.
     */
    public function fetchColumn(?int $colNumber = 0)
    {
        $rs = oci_fetch_array($this->sth, OCI_NUM + OCI_RETURN_NULLS + ($this->returnLobs ? OCI_RETURN_LOBS : 0));
        if (is_array($rs) && array_key_exists((int)$colNumber, $rs)) {
            /**
             * @todo KLUDGE No read column with type ROWID
             */
            return $this->returnLobs && is_a($rs[(int)$colNumber], 'OCI-Lob') ? null : $rs[(int)$colNumber];
        }

        return false;
    }

    /**
     * Returns an array containing all of the result set rows
     *
     * @param int|null $mode Controls the contents of the returned array as
     *   documented in PDOStatement::fetch.
     * @param mixed $fetch_argument This argument has a different meaning
     *   depending on the value of the fetchMode parameter.
     * @param mixed $args [optional] Arguments of custom class constructor
     *   when the fetch_style parameter is PDO::FETCH_CLASS.
     * @return array Array containing all of the remaining rows in the result
     *   set. The array represents each row as either an array of column values
     *   or an object with properties corresponding to each column name.
     */
    public function fetchAll(?int $mode = PDO::FETCH_OBJ, mixed $fetch_argument = null, ...$args): array
    {
        if ($mode !== null) {
            $this->setFetchMode($mode, $args);
        }

        $results = [];
        while ($row = $this->fetch()) {
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Fetches the next row and returns it as an object
     *
     * @param string|null $class
     * @param array $constructorArgs
     * @return object|false
     */
    public function fetchObject(?string $class = "stdClass", array $constructorArgs = []): object|false
    {
        $className = $class ?: $this->fetchClassName;

        $toLowercase = ($this->getAttribute(PDO::ATTR_CASE) === PDO::CASE_LOWER);
        $rs = $this->fetchArray(OCI_ASSOC + OCI_RETURN_NULLS, $toLowercase);
        if ($constructorArgs) {
            $reflectionClass = new \ReflectionClass($className);
            $object = $reflectionClass->newInstanceArgs($constructorArgs);
        } else {
            $object = new $className();
        }

        return $rs ? $this->populateObject($object, $rs) : false;
    }

    /**
     * Binds a parameter to the specified variable name
     *
     * @param string|int $parameter Parameter identifier. For a prepared statement
     *   using named placeholders, this will be a parameter name of the form
     *   :name. For a prepared statement using question mark placeholders, this
     *   will be the 1-indexed position of the parameter.
     * @param mixed $variable Name of the PHP variable to bind to the SQL
     *   statement parameter.
     * @param int $dataType Explicit data type for the parameter using the
     *   PDO::PARAM_* constants.
     * @param int $maxLength Length of the data type. To indicate that a
     *   parameter is an OUT parameter from a stored procedure, you must
     *   explicitly set the length.
     * @param array $options [optional]
     * @return bool TRUE on success or FALSE on failure.
     * @todo Map PDO datatypes to oci8 datatypes and implement support for
     *   datatypes and length.
     */
    public function bindParam(
        int|string $parameter,
        mixed      &$variable,
        int        $dataType = PDO::PARAM_STR,
        int        $maxLength = -1,
        mixed      $options = [Oci8::LOB_SQL]
    ): bool
    {
        if (is_numeric($parameter)) {
            throw new Oci8Exception("bind numerical params has not been implemented");
        }

        if ($dataType == PDO::PARAM_LOB) {
            $dataType = OCI_B_BLOB;
        }

        //Adapt the type
        switch ($dataType) {
            case PDO::PARAM_BOOL:
                $oci_type = SQLT_INT;
                break;

            case PDO::PARAM_NULL:
                $oci_type = SQLT_CHR;
                break;

            case PDO::PARAM_INT:
                $oci_type = SQLT_INT;
                break;

            case PDO::PARAM_STR:
                $oci_type = SQLT_CHR;
                break;

            case PDO::PARAM_LOB:
                $oci_type = $dataType;

                $this->lobsValue[$parameter] = $variable;
                $variable = $this->connection->getNewDescriptor(OCI_D_LOB);

                if (in_array(Oci8::LOB_SQL, $options)) {
                    $this->saveLobs[$parameter] = &$variable;
                } elseif (in_array(Oci8::LOB_PL_SQL, $options)) {
                    $this->writeLobs[$parameter] = ['type' => $oci_type, 'object' => $variable];
                }
                break;

            case PDO::PARAM_STMT:
                $oci_type = OCI_B_CURSOR;

                // Result sets require a cursor
                $variable = $this->connection->getNewCursor();
                break;

            case SQLT_NTY:
                $oci_type = SQLT_NTY;

                $schema = array_key_exists('schema', $options) ? $options['schema'] : '';
                $type_name = array_key_exists('type_name', $options) ? $options['type_name'] : '';

                // set params required to use custom type.
                $variable = $this->connection->getNewCollection($type_name, $schema);
                break;

            default:
                $oci_type = SQLT_CHR;
                break;
        }

        // Bind the parameter
        return @oci_bind_by_name($this->sth, $parameter, $variable, $maxLength, $oci_type);
    }

    /**
     * Binds a value to a parameter
     *
     * @param string|int $parameter Parameter identifier. For a prepared statement
     *   using named placeholders, this will be a parameter name of the form
     *   :name. For a prepared statement using question mark placeholders, this
     *   will be the 1-indexed position of the parameter.
     * @param mixed $variable The value to bind to the parameter.
     * @param int $dataType Explicit data type for the parameter using the
     *   PDO::PARAM_* constants.
     * @return bool TRUE on success or FALSE on failure.
     */
    public function bindValue(int|string $parameter, mixed $variable, int $dataType = PDO::PARAM_STR): bool
    {
        if (is_array($variable)) {
            $result = true;
            foreach ($variable as $var) {
                $result = $result && $this->bindParam($parameter, $var, $dataType);
            }

            return $result;
        }

        return $this->bindParam($parameter, $variable, $dataType);
    }

    /**
     * Returns the number of rows affected by the last executed statement
     *
     * @return int The number of rows.
     */
    public function rowCount(): int
    {
        return oci_num_rows($this->sth);
    }

    /**
     * Fetch the SQLSTATE associated with the last operation on the resource
     * handle
     * While this returns an error code, it merely emulates the action. If
     * there are no errors, it returns the success SQLSTATE code (00000).
     * If there are errors, it returns HY000. See errorInfo() to retrieve
     * the actual Oracle error code and message.
     *
     * @return string Error code
     */
    public function errorCode(): string
    {
        $error = $this->errorInfo();

        return $error[0];
    }

    /**
     * Fetch extended error information associated with the last operation on
     * the resource handle.
     *
     * @return array Array of error information about the last operation
     *   performed
     */
    public function errorInfo(): array
    {
        $e = oci_error($this->sth);

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
     * Sets a statement attribute
     *
     * @param int $attribute
     * @param mixed $value
     * @return bool on success or FALSE on failure.
     */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->options[$attribute] = $value;

        return true;
    }

    /**
     * Retrieve a statement attribute
     *
     * @param int $attribute
     * @return mixed The attribute value.
     */
    public function getAttribute(int $attribute): mixed
    {
        if (array_key_exists($attribute, $this->options)) {
            return $this->options[$attribute];
        }

        return null;
    }

    /**
     * Returns the number of columns in the result set
     *
     * @return int The number of columns in the statement result set. If there
     *   is no result set, it returns 0.
     */
    public function columnCount(): int
    {
        return oci_num_fields($this->sth);
    }

    /**
     * Returns metadata for a column in a result set
     * The array returned by this function is patterned after that
     * returned by \PDO::getColumnMeta(). It includes the following
     * elements:
     *     native_type
     *     driver:decl_type
     *     flags
     *     name
     *     table
     *     len
     *     precision
     *     pdo_type
     *
     * @param int $column The 0-indexed column in the result set.
     * @return array An associative array containing the above metadata values
     *   for a single column.
     */
    public function getColumnMeta(int $column): array
    {
        // Columns in oci8 are 1-based; add 1 if it's a number
        if (is_numeric($column)) {
            $column++;
        }

        $meta = [];
        $meta['native_type'] = oci_field_type($this->sth, $column);
        $meta['driver:decl_type'] = oci_field_type_raw($this->sth, $column);
        $meta['flags'] = [];
        $meta['name'] = oci_field_name($this->sth, $column);
        $meta['table'] = null;
        $meta['len'] = oci_field_size($this->sth, $column);
        $meta['precision'] = oci_field_precision($this->sth, $column);
        $meta['pdo_type'] = null;
        $meta['is_null'] = oci_field_is_null($this->sth, $column);

        return $meta;
    }

    /**
     * Fetch row from db
     * @param int $mode
     * @param bool $keyToLowercase
     * @return array|bool
     */
    private function fetchArray(int $mode, bool $keyToLowercase): array|bool
    {
        $rs = oci_fetch_array($this->sth, $mode + ($this->returnLobs ? OCI_RETURN_LOBS : 0));
        if ($rs === false) {
            return false;
        }
        if ($keyToLowercase) {
            $rs = array_change_key_case($rs);
        }

        /**
         * @todo KLUDGE No read column with type ROWID
         */
        foreach ($rs as $name => $value) {
            $ociFieldIndex = is_int($name) ? $name + 1 : $name;

            if (oci_field_type($this->sth, $ociFieldIndex) === 'ROWID') {
                $rs[$name] = null;
            }
        }

        return $rs;
    }

    /**
     * @param $object
     * @param array $fields
     */
    private function populateObject($object, array $fields)
    {
        $nullToString = ($this->getAttribute(PDO::ATTR_ORACLE_NULLS) === PDO::NULL_TO_STRING);
        $nullEmptyString = ($this->getAttribute(PDO::ATTR_ORACLE_NULLS) === PDO::NULL_EMPTY_STRING);

        // Format recordsets values depending on options
        foreach ($fields as $field => $value) {
            // convert null to empty string
            if ($nullToString && null === $value) {
                $value = '';
            }

            // convert empty string to null
            if ($nullEmptyString && '' === $value) {
                $value = null;
            }

            $object->$field = $value;
        }

        return $object;

    }

    /**
     * Advances to the next rowset in a multi-rowset statement handle
     *
     * @return bool TRUE on success or FALSE on failure.
     * @throws Oci8Exception
     * @todo Implement method
     */
    public function nextRowset(): bool
    {
        throw new Oci8Exception("nextRowset has not been implemented");
    }

    /**
     * Closes the cursor, enabling the statement to be executed again.
     *
     * @return bool TRUE on success or FALSE on failure.
     * @throws Oci8Exception
     * @todo Implement method
     */
    public function closeCursor(): bool
    {
        return oci_free_statement($this->sth);
    }

    /**
     * Dump a SQL prepared command
     *
     * @return bool TRUE on success or FALSE on failure.
     * @throws Oci8Exception
     * @todo Implement method
     */
    public function debugDumpParams(): bool
    {
        throw new Oci8Exception("debugDumpParams has not been implemented");
    }

    /**
     * Binds a column to a PHP variable.
     *
     * @param mixed $column Number of the column (1-indexed) or name of the
     *                         column in the result set. If using the column name, be aware that the
     *                         name should match the case of the column, as returned by the driver.
     * @param mixed $variable
     * @param int $dataType
     * @param int|null $maxLength A hint for pre-allocation.
     * @param mixed|null $options
     * @return bool TRUE on success or FALSE on failure.
     *
     * @todo Implement this functionality by creating a table map of the
     *       variables passed in here, and, when iterating over the values
     *       of the query or fetching rows, assign data from each column
     *       to their respective variable in the map.
     */
    public function bindColumn(
        string|int $column,
        mixed      &$variable,
        int        $dataType = null,
        int|null   $maxLength = -1,
        mixed      $options = null
    ): bool
    {
        throw new Oci8Exception('bindColumn has not been implemented');
    }
}
