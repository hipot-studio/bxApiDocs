<?php

namespace Bitrix\Main\DB;

use Bitrix\Main;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Fields\ScalarField;
use Bitrix\Main\Type;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

abstract class mysqlcommonsqlhelper extends SqlHelper
{
    /**
     * Returns an identificator escaping left character.
     *
     * @return string
     */
    public function getLeftQuote()
    {
        return '`';
    }

    /**
     * Returns an identificator escaping right character.
     *
     * @return string
     */
    public function getRightQuote()
    {
        return '`';
    }

    /**
     * Returns maximum length of an alias in a select statement.
     *
     * @return int
     */
    public function getAliasLength()
    {
        return 256;
    }

    /**
     * Returns database specific query delimiter for batch processing.
     *
     * @return string
     */
    public function getQueryDelimiter()
    {
        return ';';
    }

    /**
     * Returns function for getting current time.
     *
     * @return string
     */
    public function getCurrentDateTimeFunction()
    {
        return 'NOW()';
    }

    /**
     * Returns function for getting current date without time part.
     *
     * @return string
     */
    public function getCurrentDateFunction()
    {
        return 'CURDATE()';
    }

    /**
     * Returns function for adding seconds time interval to $from.
     * <p>
     * If $from is null or omitted, then current time is used.
     * <p>
     * $seconds and $from parameters are SQL unsafe.
     *
     * @param int $seconds how many seconds to add
     * @param int $from    datetime database field of expression
     *
     * @return string
     */
    public function addSecondsToDateTime($seconds, $from = null)
    {
        if (null === $from) {
            $from = static::getCurrentDateTimeFunction();
        }

        return 'DATE_ADD('.$from.', INTERVAL '.$seconds.' SECOND)';
    }

    /**
     * Returns function cast $value to datetime database type.
     * <p>
     * $value parameter is SQL unsafe.
     *
     * @param string $value database field or expression to cast
     *
     * @return string
     */
    public function getDatetimeToDateFunction($value)
    {
        return 'DATE('.$value.')';
    }

    /**
     * Returns database expression for converting $field value according the $format.
     * <p>
     * Following format parts converted:
     * - YYYY   A full numeric representation of a year, 4 digits
     * - MMMM   A full textual representation of a month, such as January or March
     * - MM     Numeric representation of a month, with leading zeros
     * - MI     Minutes with leading zeros
     * - M      A short textual representation of a month, three letters
     * - DD     Day of the month, 2 digits with leading zeros
     * - HH     24-hour format of an hour with leading zeros
     * - H      24-hour format of an hour without leading zeros
     * - GG     12-hour format of an hour with leading zeros
     * - G      12-hour format of an hour without leading zeros
     * - SS     Seconds with leading zeros
     * - TT     AM or PM
     * - T      AM or PM
     * <p>
     * $field parameter is SQL unsafe.
     *
     * @param string $format format string
     * @param string $field  database field or expression
     *
     * @return string
     */
    public function formatDate($format, $field = null)
    {
        static $search = [
            'YYYY',
            'MMMM',
            'MM',
            'MI',
            'DD',
            'HH',
            'GG',
            'G',
            'SS',
            'TT',
            'T',
        ];
        static $replace = [
            '%Y',
            '%M',
            '%m',
            '%i',
            '%d',
            '%H',
            '%h',
            '%l',
            '%s',
            '%p',
            '%p',
        ];

        $format = str_replace($search, $replace, $format);

        if (!str_contains($format, '%H')) {
            $format = str_replace('H', '%h', $format);
        }

        if (!str_contains($format, '%M')) {
            $format = str_replace('M', '%b', $format);
        }

        if (null === $field) {
            return $format;
        }

        return 'DATE_FORMAT('.$field.", '".$format."')";
    }

    /**
     * Returns function for concatenating database fields or expressions.
     * <p>
     * All parameters are SQL unsafe.
     *
     * @return string
     */
    public function getConcatFunction()
    {
        $str = implode(', ', \func_get_args());
        if ('' !== $str) {
            $str = 'CONCAT('.$str.')';
        }

        return $str;
    }

    /**
     * Returns function for testing database field or expressions
     * against NULL value. When it is NULL then $result will be returned.
     * <p>
     * All parameters are SQL unsafe.
     *
     * @param string $expression database field or expression for NULL test
     * @param string $result     database field or expression to return when $expression is NULL
     *
     * @return string
     */
    public function getIsNullFunction($expression, $result)
    {
        return 'IFNULL('.$expression.', '.$result.')';
    }

    /**
     * Returns function for getting length of database field or expression.
     * <p>
     * $field parameter is SQL unsafe.
     *
     * @param string $field database field or expression
     *
     * @return string
     */
    public function getLengthFunction($field)
    {
        return 'LENGTH('.$field.')';
    }

    /**
     * Returns function for converting string value into datetime.
     * $value must be in YYYY-MM-DD HH:MI:SS format.
     * <p>
     * $value parameter is SQL unsafe.
     *
     * @param string $value string in YYYY-MM-DD HH:MI:SS format
     *
     * @return string
     *
     * @see MssqlSqlHelper::formatDate
     */
    public function getCharToDateFunction($value)
    {
        return "'".$value."'";
    }

    /**
     * Returns function for converting database field or expression into string.
     * <p>
     * Result string will be in YYYY-MM-DD HH:MI:SS format.
     * <p>
     * $fieldName parameter is SQL unsafe.
     *
     * @param string $fieldName database field or expression
     *
     * @return string
     *
     * @see MssqlSqlHelper::formatDate
     */
    public function getDateToCharFunction($fieldName)
    {
        return $fieldName;
    }

    /**
     * Returns callback to be called for a field value on fetch.
     * Used for soft conversion. For strict results @see Entity\Query\Result::setStrictValueConverters().
     *
     * @param ScalarField $field type "source"
     *
     * @return callable|false
     */
    public function getConverter(ScalarField $field)
    {
        if ($field instanceof ORM\Fields\DatetimeField) {
            return [$this, 'convertFromDbDateTime'];
        }
        if ($field instanceof ORM\Fields\DateField) {
            return [$this, 'convertFromDbDate'];
        }

        return parent::getConverter($field);
    }

    /**
     * @deprecated
     * Converts string into \Bitrix\Main\Type\DateTime object.
     * <p>
     * Helper function.
     *
     * @param string $value value fetched
     *
     * @return null|DateTime
     *
     * @see Main\Db\MysqlCommonSqlHelper::getConverter
     */
    public function convertDatetimeField($value)
    {
        return $this->convertFromDbDateTime($value);
    }

    /**
     * @return null|DateTime
     *
     * @throws Main\ObjectException
     */
    public function convertFromDbDateTime($value)
    {
        if (null !== $value && '0000-00-00 00:00:00' !== $value) {
            return new DateTime($value, 'Y-m-d H:i:s');
        }

        return null;
    }

    /**
     * @deprecated
     * Converts string into \Bitrix\Main\Type\Date object.
     * <p>
     * Helper function.
     *
     * @param string $value value fetched
     *
     * @return null|Date
     *
     * @see Main\Db\MysqlCommonSqlHelper::getConverter
     */
    public function convertDateField($value)
    {
        return $this->convertFromDbDate($value);
    }

    /**
     * @return null|Date
     *
     * @throws Main\ObjectException
     */
    public function convertFromDbDate($value)
    {
        if (null !== $value && '0000-00-00' !== $value) {
            return new Date($value, 'Y-m-d');
        }

        return null;
    }

    /**
     * @param string $fieldName
     *
     * return string
     */
    public function castToChar($fieldName)
    {
        return 'CAST('.$fieldName.' AS char)';
    }

    /**
     * @param string $fieldName
     *
     * return string
     */
    public function softCastTextToChar($fieldName)
    {
        return $fieldName;
    }

    /**
     * Returns a column type according to ScalarField object.
     *
     * @param ScalarField $field type "source"
     *
     * @return string
     */
    public function getColumnTypeByField(ScalarField $field)
    {
        if ($field instanceof ORM\Fields\IntegerField) {
            return 'int';
        }
        if ($field instanceof ORM\Fields\DecimalField) {
            $defaultPrecision = 18;
            $defaultScale = 2;

            $precision = $field->getPrecision() > 0 ? $field->getPrecision() : $defaultPrecision;
            $scale = $field->getScale() > 0 ? $field->getScale() : $defaultScale;

            if ($scale >= $precision) {
                $precision = $defaultPrecision;
                $scale = $defaultScale;
            }

            return "decimal({$precision}, {$scale})";
        }
        if ($field instanceof ORM\Fields\FloatField) {
            return 'double';
        }
        if ($field instanceof ORM\Fields\DatetimeField) {
            return 'datetime';
        }
        if ($field instanceof ORM\Fields\DateField) {
            return 'date';
        }
        if ($field instanceof ORM\Fields\TextField) {
            return 'text';
        }
        if ($field instanceof ORM\Fields\BooleanField) {
            $values = $field->getValues();

            if (preg_match('/^[0-9]+$/', $values[0]) && preg_match('/^[0-9]+$/', $values[1])) {
                return 'int';
            }

            return 'varchar('.max(mb_strlen($values[0]), mb_strlen($values[1])).')';
        } elseif ($field instanceof ORM\Fields\EnumField) {
            return 'varchar('.max(array_map('strlen', $field->getValues())).')';
        }

        // string by default
        $defaultLength = false;
        foreach ($field->getValidators() as $validator) {
            if ($validator instanceof ORM\Fields\Validators\LengthValidator) {
                if (false === $defaultLength || $defaultLength > $validator->getMax()) {
                    $defaultLength = $validator->getMax();
                }
            }
        }

        return 'varchar('.($defaultLength > 0 ? $defaultLength : 255).')';
    }

    /**
     * Transforms Sql according to $limit and $offset limitations.
     * <p>
     * You must specify $limit when $offset is set.
     *
     * @param string $sql    sql text
     * @param int    $limit  maximum number of rows to return
     * @param int    $offset offset of the first row to return, starting from 0
     *
     * @return string
     *
     * @throws ArgumentException
     */
    public function getTopSql($sql, $limit, $offset = 0)
    {
        $offset = (int) $offset;
        $limit = (int) $limit;

        if ($offset > 0 && $limit <= 0) {
            throw new ArgumentException('Limit must be set if offset is set');
        }

        if ($limit > 0) {
            $sql .= "\nLIMIT ".$offset.', '.$limit."\n";
        }

        return $sql;
    }

    /**
     * Builds the strings for the SQL MERGE command for the given table.
     *
     * @param string $tableName     a table name
     * @param array  $primaryFields array("column")[] Primary key columns list
     * @param array  $insertFields  array("column" => $value)[] What to insert
     * @param array  $updateFields  array("column" => $value)[] How to update
     *
     * @return array (merge)
     */
    public function prepareMerge($tableName, array $primaryFields, array $insertFields, array $updateFields)
    {
        $insert = $this->prepareInsert($tableName, $insertFields);
        $update = $this->prepareUpdate($tableName, $updateFields);

        if (
            $insert && '' !== $insert[0] && '' !== $insert[1]
            && $update && '' !== $update[1]
        ) {
            $sql = '
				INSERT INTO '.$this->quote($tableName).' ('.$insert[0].')
				VALUES ('.$insert[1].')
				ON DUPLICATE KEY UPDATE '.$update[0].'
			';
        } else {
            $sql = '';
        }

        return [
            $sql,
        ];
    }

    public function getConditionalAssignment(string $field, string $value): string
    {
        $field = $this->quote($field);
        $value = $this->convertToDbString($value);
        $hash = $this->convertToDbString(sha1($value));

        return "IF(SHA1({$field}) = {$hash}, {$field}, {$value})";
    }
}
