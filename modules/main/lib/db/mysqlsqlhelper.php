<?php

namespace Bitrix\Main\DB;

use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Fields\ScalarField;

class mysqlsqlhelper extends MysqlCommonSqlHelper
{
    /**
     * Escapes special characters in a string for use in an SQL statement.
     *
     * @param string $value     value to be escaped
     * @param int    $maxLength limits string length if set
     *
     * @return string
     */
    public function forSql($value, $maxLength = 0)
    {
        if ($maxLength > 0) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return mysql_real_escape_string($value, $this->connection->getResource());
    }

    /**
     * Returns instance of a descendant from Entity\ScalarField
     * that matches database type.
     *
     * @param string $name       database column name
     * @param mixed  $type       database specific type
     * @param array  $parameters additional information
     *
     * @return ScalarField
     */
    public function getFieldByColumnType($name, $type, ?array $parameters = null)
    {
        switch ($type) {
            case 'int':
                return new ORM\Fields\IntegerField($name);

            case 'real':
                return new ORM\Fields\FloatField($name);

            case 'datetime':
            case 'timestamp':
                return new ORM\Fields\DatetimeField($name);

            case 'date':
                return new ORM\Fields\DateField($name);
        }

        return new ORM\Fields\StringField($name);
    }
}
