<?php

namespace Bitrix\BIConnector\Integration\Crm\ProductProperty;

use Bitrix\BIConnector\DB\BiSqlHelperInterface;

/**
 * Description of the VALUE_NAME field of the dataset crm_product_property_value.
 *
 * A property value is stored as an identifier for a list property (type L), for a binding to an
 * element (type E) and for a binding to a section (type G); for the other types the column already
 * holds the value itself. VALUE_NAME reads the name behind such an identifier.
 *
 * Every lookup carries the identifiers of the properties of its own type, so it reaches only the rows
 * it can answer for: a dictionary entry never lands on a binding value, and a catalog without
 * properties of a type pays nothing for that type. A binding reaches its table through the numeric
 * projection of the value, so the cast stays on the side of the table of values and the primary key of
 * the linked table stays usable.
 *
 * The dictionary is always joined. The linked element and section are joined on MySQL, which reads
 * them by the primary key, and read by a scalar subquery on PostgreSQL: b_iblock_element and
 * b_iblock_section hold the rows of every iblock of the portal, and on a full selection PostgreSQL
 * answers such a join with a hash built over a scan of the whole table, while the subquery stays a
 * lookup by the primary key. On MySQL the same subquery doubles the time of the selection.
 */
class ValueNameFields
{
	public const ENUM_ALIAS = 'IPE';

	public const ELEMENT_ALIAS = 'BEL';

	public const SECTION_ALIAS = 'BSC';

	private const TYPE_LIST = 'L';

	private const TYPE_ELEMENT = 'E';

	private const TYPE_SECTION = 'G';

	/**
	 * Description of VALUE_NAME for a table whose VALUE column holds the value as text.
	 *
	 * These are b_iblock_element_property of the storage of version 1 and
	 * b_iblock_element_prop_m<iblock> of the storage of version 2: both keep the property behind
	 * every row in IBLOCK_PROPERTY_ID and its numeric projection in VALUE_NUM.
	 *
	 * @param BiSqlHelperInterface $helper SQL helper of the connection the dataset is assembled for.
	 * @param string $tableAlias Alias of the table of values.
	 * @param array<int, array{TYPE: string, MULTIPLE: bool}> $properties Properties whose values the table holds.
	 *
	 * @return array
	 */
	public static function forTextValueTable(
		BiSqlHelperInterface $helper,
		string $tableAlias,
		array $properties
	): array
	{
		$propertyIds = self::groupPropertyIdsByType($properties);
		$propertyColumn = $tableAlias . '.IBLOCK_PROPERTY_ID';
		$valueColumn = $tableAlias . '.VALUE';
		$numericValue = $helper->castNumericToInt($tableAlias . '.VALUE_NUM');

		$names = [];
		$joins = [];

		if ($propertyIds[self::TYPE_LIST])
		{
			$names[] = self::ENUM_ALIAS . '.VALUE';
			$joins[] =
				'LEFT JOIN b_iblock_property_enum ' . self::ENUM_ALIAS
				. ' ON ' . $helper->castIntToChar(self::ENUM_ALIAS . '.ID') . ' = '
				. self::ofOwnProperties($propertyColumn, $propertyIds[self::TYPE_LIST], $valueColumn)
				. ' AND ' . self::ENUM_ALIAS . '.PROPERTY_ID = ' . $propertyColumn
			;
		}

		$linkedTables = [
			self::TYPE_ELEMENT => ['b_iblock_element', self::ELEMENT_ALIAS],
			self::TYPE_SECTION => ['b_iblock_section', self::SECTION_ALIAS],
		];
		foreach ($linkedTables as $type => [$table, $alias])
		{
			if (!$propertyIds[$type])
			{
				continue;
			}

			if (self::readsLinkedNameBySubquery($helper))
			{
				$names[] = self::ofOwnProperties(
					$propertyColumn,
					$propertyIds[$type],
					self::linkedNameSubquery($table, $alias, $numericValue),
				);
			}
			else
			{
				$names[] = $alias . '.NAME';
				$joins[] = self::linkedNameJoin(
					$table,
					$alias,
					self::ofOwnProperties($propertyColumn, $propertyIds[$type], $numericValue),
				);
			}
		}

		return self::field(self::coalesce($names, $valueColumn), $joins);
	}

	/**
	 * Description of VALUE_NAME for the PROPERTY_<id> column of b_iblock_element_prop_s<iblock>.
	 *
	 * The storage of version 2 gives every single property its own column, so the type of the
	 * property is known exactly and the branch needs at most one lookup. The column is integer for the
	 * types that hold an identifier, which makes it the lookup key as it is.
	 *
	 * @param BiSqlHelperInterface $helper SQL helper of the connection the dataset is assembled for.
	 * @param string $tableAlias Alias of the table of single values.
	 * @param string $column Column of the property, PROPERTY_<id>.
	 * @param int $propertyId Identifier of the property.
	 * @param string $propertyType Type of the property.
	 *
	 * @return array
	 */
	public static function forSingleValueColumn(
		BiSqlHelperInterface $helper,
		string $tableAlias,
		string $column,
		int $propertyId,
		string $propertyType
	): array
	{
		$valueColumn = $tableAlias . '.' . $column;
		$names = [];
		$joins = [];

		if ($propertyType === self::TYPE_LIST)
		{
			$names[] = self::ENUM_ALIAS . '.VALUE';
			$joins[] =
				'LEFT JOIN b_iblock_property_enum ' . self::ENUM_ALIAS
				. ' ON ' . self::ENUM_ALIAS . '.PROPERTY_ID = \'' . $propertyId . '\''
				. ' AND ' . self::ENUM_ALIAS . '.ID = ' . $valueColumn
			;
		}
		elseif ($propertyType === self::TYPE_ELEMENT || $propertyType === self::TYPE_SECTION)
		{
			[$table, $alias] = $propertyType === self::TYPE_ELEMENT
				? ['b_iblock_element', self::ELEMENT_ALIAS]
				: ['b_iblock_section', self::SECTION_ALIAS]
			;

			if (self::readsLinkedNameBySubquery($helper))
			{
				$names[] = self::linkedNameSubquery($table, $alias, $valueColumn);
			}
			else
			{
				$names[] = $alias . '.NAME';
				$joins[] = self::linkedNameJoin($table, $alias, $valueColumn);
			}
		}

		return self::field(self::coalesce($names, $helper->castNumericToChar($valueColumn)), $joins);
	}

	/**
	 * @param string[] $names Names read through the joins, in the order they are resolved.
	 * @param string $rawValue Value of the row itself, returned when no join answers.
	 *
	 * @return string
	 */
	private static function coalesce(array $names, string $rawValue): string
	{
		if (!$names)
		{
			return $rawValue;
		}

		return 'COALESCE(' . implode(', ', [...$names, $rawValue]) . ')';
	}

	/**
	 * @param string $fieldName Expression of the field.
	 * @param string[] $joins Joins the expression needs, in the order they are printed.
	 *
	 * @return array
	 */
	private static function field(string $fieldName, array $joins): array
	{
		return [
			'IS_METRIC' => 'N',
			'FIELD_NAME' => $fieldName,
			'FIELD_TYPE' => 'string',
			// The field declares no TABLE_ALIAS, so which of the two keys the engine reads is not
			// guaranteed; both carry the same list of left joins.
			'JOIN' => $joins,
			'LEFT_JOIN' => $joins,
		];
	}

	/**
	 * Expression that only a row of a property of the given type computes; for any other row it is NULL.
	 *
	 * As the key of a join, the list of properties belongs inside the key rather than beside it: a
	 * condition standing next to the key is applied after the lookup in the linked table, so the join
	 * would run for every row of the selection. A NULL key matches nothing and is not looked up at all.
	 * Around a subquery, the subquery is not run for a row of a foreign property.
	 *
	 * @param string $propertyColumn Column holding the property behind the row.
	 * @param int[] $propertyIds Properties of one type.
	 * @param string $value Expression computed for a row of such a property.
	 *
	 * @return string
	 */
	private static function ofOwnProperties(string $propertyColumn, array $propertyIds, string $value): string
	{
		return
			'CASE WHEN ' . $propertyColumn . ' IN (' . implode(',', $propertyIds) . ')'
			. ' THEN ' . $value . ' END'
		;
	}

	private static function readsLinkedNameBySubquery(BiSqlHelperInterface $helper): bool
	{
		return $helper instanceof \Bitrix\Main\DB\PgsqlSqlHelper;
	}

	/**
	 * @param string $table Linked table, b_iblock_element or b_iblock_section.
	 * @param string $alias Alias of the linked table.
	 * @param string $id Expression of the identifier of the linked row.
	 *
	 * @return string
	 */
	private static function linkedNameJoin(string $table, string $alias, string $id): string
	{
		return 'LEFT JOIN ' . $table . ' ' . $alias . ' ON ' . $alias . '.ID = ' . $id;
	}

	/**
	 * @param string $table Linked table, b_iblock_element or b_iblock_section.
	 * @param string $alias Alias of the linked table inside the subquery.
	 * @param string $id Expression of the identifier of the linked row.
	 *
	 * @return string
	 */
	private static function linkedNameSubquery(string $table, string $alias, string $id): string
	{
		return '(SELECT ' . $alias . '.NAME FROM ' . $table . ' ' . $alias . ' WHERE ' . $alias . '.ID = ' . $id . ')';
	}

	/**
	 * @param array<int, array{TYPE: string, MULTIPLE: bool}> $properties Properties of the iblocks the table holds.
	 *
	 * @return array<string, int[]>
	 */
	private static function groupPropertyIdsByType(array $properties): array
	{
		$propertyIds = [
			self::TYPE_LIST => [],
			self::TYPE_ELEMENT => [],
			self::TYPE_SECTION => [],
		];

		foreach ($properties as $propertyId => $property)
		{
			$type = $property['TYPE'] ?? '';
			if (isset($propertyIds[$type]))
			{
				$propertyIds[$type][] = (int)$propertyId;
			}
		}

		return $propertyIds;
	}
}
