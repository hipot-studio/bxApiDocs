<?php

namespace Bitrix\Main\DB;

use Bitrix\Main\Diag\SqlTrackerQuery;
use Bitrix\Main\ORM\Fields\ScalarField;

class mysqlresult extends Result
{
    /** @var ScalarField[] */
    protected $resultFields;

    /**
     * @param resource        $result       database-specific query result
     * @param Connection      $dbConnection connection object
     * @param SqlTrackerQuery $trackerQuery helps to collect debug information
     */
    public function __construct($result, Connection $dbConnection, ?SqlTrackerQuery $trackerQuery = null)
    {
        parent::__construct($result, $dbConnection, $trackerQuery);
    }

    /**
     * Returns the number of rows in the result.
     *
     * @return int
     */
    public function getSelectedRowsCount()
    {
        return mysql_num_rows($this->resource);
    }

    /**
     * Returns an array of fields according to columns in the result.
     *
     * @return ScalarField[]
     */
    public function getFields()
    {
        if (null === $this->resultFields) {
            $this->resultFields = [];
            if (\is_resource($this->resource)) {
                $numFields = mysql_num_fields($this->resource);
                if ($numFields > 0 && $this->connection) {
                    $helper = $this->connection->getSqlHelper();
                    for ($i = 0; $i < $numFields; ++$i) {
                        $name = mysql_field_name($this->resource, $i);
                        $type = mysql_field_type($this->resource, $i);

                        $this->resultFields[$name] = $helper->getFieldByColumnType($name, $type);
                    }
                }
            }
        }

        return $this->resultFields;
    }

    /**
     * Returns next result row or false.
     *
     * @return array|false
     */
    protected function fetchRowInternal()
    {
        return mysql_fetch_assoc($this->resource);
    }
}
