<?php

namespace Bitrix\Main\DB;

use Bitrix\Main\Diag\SqlTrackerQuery;

class mysqlconnection extends MysqlCommonConnection
{
    /**
     * Disconnects from the database.
     * Does nothing if there was no connection established.
     */
    public function disconnectInternal()
    {
        if (!$this->isConnected) {
            return;
        }

        mysql_close($this->resource);

        $this->isConnected = false;
    }

    public function getInsertedId()
    {
        $this->connectInternal();

        return mysql_insert_id($this->resource);
    }

    public function getAffectedRowsCount()
    {
        return mysql_affected_rows($this->getResource());
    }

    // Type, version, cache, etc.

    public function getVersion()
    {
        if (null === $this->version) {
            $version = $this->queryScalar('SELECT VERSION()');
            if (null !== $version) {
                $version = trim($version);
                preg_match('#[0-9]+\\.[0-9]+\\.[0-9]+#', $version, $ar);
                $this->version = $ar[0];
            }
        }

        return [$this->version, null];
    }

    /**
     * Selects the default database for database queries.
     *
     * @param string $database database name
     *
     * @return bool
     */
    public function selectDatabase($database)
    {
        return mysql_select_db($database, $this->resource);
    }
    // SqlHelper

    protected function createSqlHelper()
    {
        return new MysqlSqlHelper($this);
    }

    // Connection and disconnection

    /**
     * Establishes a connection to the database.
     * Includes php_interface/after_connect_d7.php on success.
     * Throws exception on failure.
     *
     * @throws ConnectionException
     */
    protected function connectInternal()
    {
        if ($this->isConnected) {
            return;
        }

        if (($this->options & self::PERSISTENT) !== 0) {
            $connection = mysql_pconnect($this->host, $this->login, $this->password);
        } else {
            $connection = mysql_connect($this->host, $this->login, $this->password, true);
        }

        if (!$connection) {
            throw new ConnectionException('Mysql connect error ['.$this->host.', '.gethostbyname($this->host).']', mysql_error());
        }

        if (null !== $this->database) {
            if (!mysql_select_db($this->database, $connection)) {
                throw new ConnectionException('Mysql select db error ['.$this->database.']', mysql_error($connection));
            }
        }

        $this->resource = $connection;
        $this->isConnected = true;

        $this->afterConnected();
    }

    // Query

    protected function queryInternal($sql, ?array $binds = null, ?SqlTrackerQuery $trackerQuery = null)
    {
        $this->connectInternal();

        if (null !== $trackerQuery) {
            $trackerQuery->startQuery($sql, $binds);
        }

        $result = mysql_query($sql, $this->resource);

        if (null !== $trackerQuery) {
            $trackerQuery->finishQuery();
        }

        $this->lastQueryResult = $result;

        if (!$result) {
            throw new SqlQueryException('Mysql query error', mysql_error($this->resource), $sql);
        }

        return $result;
    }

    protected function createResult($result, ?SqlTrackerQuery $trackerQuery = null)
    {
        return new MysqlResult($result, $this, $trackerQuery);
    }

    protected function getErrorMessage()
    {
        return \sprintf('[%s] %s', mysql_errno($this->resource), mysql_error($this->resource));
    }
}
