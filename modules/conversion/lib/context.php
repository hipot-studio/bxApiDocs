<?php

namespace Bitrix\Conversion;

use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\DB;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;

/** @internal */
final class context
{
    public const EMPTY_CONTEXT_ID = 0; // Context with no attributes.

    private $id;
    private $attributes = [];

    /** Add value to counter. If counter not exists set counter to value. Save to database.
     * @param Date      $day   - counter date
     * @param string    $name  - counter name
     * @param float|int $value - number to add
     *
     * @throws ArgumentTypeException
     * @throws SystemException
     */
    public function addCounter(Date $day, $name, $value)
    {
        if (!\is_string($name)) {
            throw new ArgumentTypeException('name', 'string');
        }

        if (!is_numeric($value)) {
            throw new ArgumentTypeException('value', 'numeric');
        }

        if (($id = $this->id) === null) {
            throw new SystemException('Cannot add counter without context!');
        }

        // save to database

        $primary = [
            'DAY' => $day,
            'CONTEXT_ID' => $id,
            'NAME' => $name,
        ];

        $data = ['VALUE' => new DB\SqlExpression('?# + ?f', 'VALUE', $value)];

        $result = Internals\ContextCounterDayTable::update($primary, $data);

        if (0 === $result->getAffectedRowsCount()) {
            try {
                $result = Internals\ContextCounterDayTable::add($primary + ['VALUE' => $value]);
            } catch (DB\SqlQueryException $e) {
                $result = Internals\ContextCounterDayTable::update($primary, $data);
            }
        }

        $result->isSuccess(); // TODO isSuccess
    }

    /** Set attribute with value.
     * @param string                $name  - attribute name
     * @param null|float|int|string $value - attribute value
     *
     * @throws ArgumentTypeException
     * @throws SystemException
     */
    public function setAttribute($name, $value = null)
    {
        if (!\is_string($name)) {
            throw new ArgumentTypeException('name', 'string');
        }

        if (!(\is_scalar($value) || null === $value)) {
            throw new ArgumentTypeException('name', 'scalar');
        }

        if (null !== $this->id) {
            throw new SystemException('Cannot set attribute for existent context!');
        }

        $this->attributes[$name.':'.$value] = ['NAME' => $name, 'VALUE' => $value];
    }

    /** Save context & attributes to database */
    private function save()
    {
        if (($id = &$this->id) !== null) {
            throw new SystemException('Cannot save existent context!');
        }

        // save to database

        $id = self::EMPTY_CONTEXT_ID;

        if ($attributes = $this->attributes) {
            $snapshot = self::getSnapshot($attributes);

            $query = [
                'limit' => 1,
                'select' => ['ID'],
                'filter' => ['=SNAPSHOT' => $snapshot],
            ];

            if ($row = Internals\ContextTable::getList($query)->fetch()) {
                $id = (int) $row['ID'];
            } else {
                try {
                    $result = Internals\ContextTable::add(['SNAPSHOT' => $snapshot]);

                    if ($result->isSuccess()) {
                        $id = $result->getId();

                        foreach ($attributes as $attribute) {
                            // TODO resetContext if not success and return null!!!
                            $result = Internals\ContextAttributeTable::add([
                                'CONTEXT_ID' => $id,
                                'NAME' => $attribute['NAME'],
                                'VALUE' => $attribute['VALUE'],
                            ]);
                        }
                    } else {
                        throw new DB\SqlQueryException();
                    }
                } catch (DB\SqlQueryException $e) {
                    if ($row = Internals\ContextTable::getList($query)->fetch()) {
                        $id = (int) $row['ID'];
                    }
                }
            }
        }
    }

    private static function getSnapshot(array $attributes)
    {
        $keys = array_keys($attributes);
        sort($keys);

        $str1 = implode(';', $keys);
        $str2 = strrev($str1);

        return md5($str1).md5($str2);
    }
}
