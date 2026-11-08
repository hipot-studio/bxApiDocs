<?php

namespace Bitrix\BIConnector\Integration\Crm\ProductProperty;

use Bitrix\BIConnector\DB\BiSqlHelperInterface;

/**
 * Description of the dataset crm_product_property_value.
 *
 * The shape of the description follows the versions of the storage of the two iblocks of the CRM
 * catalog: a table of version 1 holds the values of every iblock it is filtered by, while an iblock
 * of version 2 brings its own table of multiple values and a column per single property, and every
 * such table becomes a branch of the UNION. The versions arrive as arguments rather than being read
 * here, so all four combinations are reachable apart from the iblocks a portal happens to carry.
 */
class ValueDatasetDescription
{
	private const MAIN_ALIAS = 'IEP';

	private const PROPERTY_ALIAS = 'IP';

	/**
	 * @param BiSqlHelperInterface $helper SQL helper of the connection the dataset is assembled for.
	 * @param CatalogIblock $catalog Iblock of the products of the CRM catalog.
	 * @param CatalogIblock|null $offers Iblock of the offers of the CRM catalog, when the catalog has one.
	 * @param array<int, array{TYPE: string, MULTIPLE: bool, IBLOCK_ID: int}> $properties Properties of the iblocks.
	 *
	 * @return array Empty when the pair of versions is none of the four the storage of iblock knows.
	 */
	public static function build(
		BiSqlHelperInterface $helper,
		CatalogIblock $catalog,
		?CatalogIblock $offers,
		array $properties
	): array
	{
		$offersVersion = $offers?->version ?? 1;

		if ($catalog->version === 1 && $offersVersion === 1)
		{
			return self::textValueTable(
				$helper,
				[$catalog->id, $offers?->id],
				[
					'IS_PRIMARY' => 'Y',
					...self::getIdField(),
				],
				$properties,
			);
		}

		if ($catalog->version === 1 && $offersVersion === 2)
		{
			return [
				...self::textValueTable(
					$helper,
					[$catalog->id],
					self::getUnionCompatibleIdField($helper),
					self::propertiesOfIblock($properties, $catalog->id),
				),
				'UNIONS' => [
					...($offers !== null ? [self::getMultipleUnion($helper, $offers->id, $properties)] : []),
					...($offers !== null ? self::getSingleUnion($helper, $offers->id, $properties) : []),
				],
			];
		}

		if ($catalog->version === 2 && $offersVersion === 1)
		{
			return [
				...self::textValueTable(
					$helper,
					[$offers?->id],
					self::getUnionCompatibleIdField($helper),
					self::propertiesOfIblock($properties, $offers?->id ?? 0),
				),
				'UNIONS' => [
					self::getMultipleUnion($helper, $catalog->id, $properties),
					...self::getSingleUnion($helper, $catalog->id, $properties),
				],
			];
		}

		if ($catalog->version === 2 && $offersVersion === 2)
		{
			return [
				'TABLE_NAME' => 'b_iblock_element_prop_m' . $catalog->id,
				'TABLE_ALIAS' => self::MAIN_ALIAS,
				'FIELDS' => [
					'ID' => [
						'IS_PRIMARY' => 'Y',
						...self::getUnionCompatibleIdField($helper),
					],
					'PRODUCT_ID' => [
						'FIELD_NAME' => self::MAIN_ALIAS . '.IBLOCK_ELEMENT_ID',
						'FIELD_TYPE' => 'int',
					],
					'PROPERTY_ID' => [
						'IS_PRIMARY' => 'Y',
						'FIELD_NAME' => self::MAIN_ALIAS . '.IBLOCK_PROPERTY_ID',
						'FIELD_TYPE' => 'int',
					],
					'VALUE' => [
						'IS_METRIC' => 'N',
						'FIELD_NAME' => self::MAIN_ALIAS . '.VALUE',
						'FIELD_TYPE' => 'string',
					],
					'VALUE_NAME' => ValueNameFields::forTextValueTable(
						$helper,
						self::MAIN_ALIAS,
						self::propertiesOfIblock($properties, $catalog->id),
					),
				],
				'UNIONS' => [
					...self::getSingleUnion($helper, $catalog->id, $properties),
					...($offers !== null ? [self::getMultipleUnion($helper, $offers->id, $properties)] : []),
					...($offers !== null ? self::getSingleUnion($helper, $offers->id, $properties) : []),
				],
			];
		}

		return [];
	}

	/**
	 * Types of the properties of the iblocks the dataset reads.
	 *
	 * The description of the dataset is assembled on every logical request of BI, so the types are
	 * resolved once and handed to every branch: a branch of the UNION is built per single property,
	 * and a query of its own in each of them would multiply by the number of properties.
	 *
	 * @param int ...$iblockIds Iblocks of the CRM catalog.
	 *
	 * @return array<int, array{TYPE: string, MULTIPLE: bool, IBLOCK_ID: int}>
	 */
	public static function loadProperties(int ...$iblockIds): array
	{
		if (!$iblockIds)
		{
			return [];
		}

		$rows = \Bitrix\Iblock\PropertyTable::getList([
			'select' => ['ID', 'IBLOCK_ID', 'PROPERTY_TYPE', 'MULTIPLE'],
			'filter' => ['=IBLOCK_ID' => array_values(array_unique($iblockIds))],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();

		$properties = [];
		foreach ($rows as $row)
		{
			$properties[(int)$row['ID']] = [
				'TYPE' => (string)$row['PROPERTY_TYPE'],
				'MULTIPLE' => $row['MULTIPLE'] === 'Y',
				'IBLOCK_ID' => (int)$row['IBLOCK_ID'],
			];
		}

		return $properties;
	}

	/**
	 * Main branch over b_iblock_element_property, the table of the storage of version 1.
	 *
	 * @param BiSqlHelperInterface $helper SQL helper of the connection the dataset is assembled for.
	 * @param array<int, int|null> $iblockIds Iblocks whose values the branch reads.
	 * @param array $idField Description of the ID field of the branch.
	 * @param array<int, array{TYPE: string, MULTIPLE: bool, IBLOCK_ID: int}> $properties Properties the table holds.
	 *
	 * @return array
	 */
	private static function textValueTable(
		BiSqlHelperInterface $helper,
		array $iblockIds,
		array $idField,
		array $properties
	): array
	{
		return [
			'TABLE_NAME' => 'b_iblock_element_property',
			'TABLE_ALIAS' => self::MAIN_ALIAS,
			'FILTER' => [
				'=IBLOCK_ID' => $iblockIds,
			],
			'FILTER_FIELDS' => [
				'IBLOCK_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => self::PROPERTY_ALIAS . '.IBLOCK_ID',
					'FIELD_TYPE' => 'int',
					'TABLE_ALIAS' => self::PROPERTY_ALIAS,
					'JOIN' =>
						'INNER JOIN b_iblock_property ' . self::PROPERTY_ALIAS
						. ' ON ' . self::PROPERTY_ALIAS . '.ID = ' . self::MAIN_ALIAS . '.IBLOCK_PROPERTY_ID'
					,
					'LEFT_JOIN' =>
						'LEFT JOIN b_iblock_property ' . self::PROPERTY_ALIAS
						. ' ON ' . self::PROPERTY_ALIAS . '.ID = ' . self::MAIN_ALIAS . '.IBLOCK_PROPERTY_ID'
					,
				],
			],
			'FIELDS' => [
				'ID' => $idField,
				'PRODUCT_ID' => [
					'FIELD_NAME' => self::MAIN_ALIAS . '.IBLOCK_ELEMENT_ID',
					'FIELD_TYPE' => 'int',
				],
				'PROPERTY_ID' => [
					'FIELD_NAME' => self::MAIN_ALIAS . '.IBLOCK_PROPERTY_ID',
					'FIELD_TYPE' => 'int',
				],
				'VALUE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => self::MAIN_ALIAS . '.VALUE',
					'FIELD_TYPE' => 'string',
				],
				'VALUE_NAME' => ValueNameFields::forTextValueTable($helper, self::MAIN_ALIAS, $properties),
			],
		];
	}

	/**
	 * Description of the ID field of a description that has no branches of the UNION.
	 *
	 * The column is printed as it is. It is the primary key of the table, and the connector takes the
	 * expression of the primary field as the key of the sort and as the key of the join of the
	 * subquery that limits the selection, where a cast costs the index of that key.
	 *
	 * @return array
	 */
	private static function getIdField(): array
	{
		return [
			'FIELD_NAME' => self::MAIN_ALIAS . '.ID',
			'FIELD_TYPE' => 'string',
		];
	}

	/**
	 * Description of the ID field of a main branch that stands next to UNION branches.
	 *
	 * A UNION branch builds ID by concatenation, so ID is a character value there. PostgreSQL matches
	 * the column types of a UNION and refuses a statement that mixes an integer with a text, so the
	 * main branch casts its column as well. On MySQL the cast is empty and the statement stays
	 * unchanged.
	 *
	 * A primary field pays for that cast: the connector reuses its expression as the sort key and as
	 * the join key of the limiting subquery, so PostgreSQL leaves the primary key index unused there.
	 * Avoiding this takes a separate primary key expression in the description, read by the connector.
	 *
	 * @param BiSqlHelperInterface $helper SQL helper of the connection the dataset is assembled for.
	 *
	 * @return array
	 */
	private static function getUnionCompatibleIdField(BiSqlHelperInterface $helper): array
	{
		return [
			'FIELD_NAME' => $helper->castIntToChar(self::MAIN_ALIAS . '.ID'),
			'FIELD_TYPE' => 'string',
		];
	}

	/**
	 * @param array<int, array{TYPE: string, MULTIPLE: bool, IBLOCK_ID: int}> $properties Properties of the catalog.
	 * @param int $iblockId Iblock whose values the table holds.
	 *
	 * @return array<int, array{TYPE: string, MULTIPLE: bool, IBLOCK_ID: int}>
	 */
	private static function propertiesOfIblock(array $properties, int $iblockId): array
	{
		return array_filter($properties, static fn(array $property): bool => $property['IBLOCK_ID'] === $iblockId);
	}

	/**
	 * A branch per single property of the iblock, reading the column of that property.
	 *
	 * @param BiSqlHelperInterface $helper SQL helper of the connection the dataset is assembled for.
	 * @param int $iblockId Iblock of version 2 whose single values the table holds.
	 * @param array<int, array{TYPE: string, MULTIPLE: bool, IBLOCK_ID: int}> $properties Properties of the catalog.
	 *
	 * @return array
	 */
	private static function getSingleUnion(BiSqlHelperInterface $helper, int $iblockId, array $properties): array
	{
		$union = [];
		foreach (self::propertiesOfIblock($properties, $iblockId) as $propertyId => $property)
		{
			if ($property['MULTIPLE'])
			{
				continue;
			}

			$propertyFieldName = 'PROPERTY_' . $propertyId;
			$propertyIdFieldName = '\'' . $propertyId . '\'';

			$union[] = [
				'TABLE_NAME' => 'b_iblock_element_prop_s' . $iblockId,
				'TABLE_ALIAS' => self::MAIN_ALIAS,
				'FIELDS' => [
					'ID' => [
						'FIELD_NAME' => $helper->getConcatFunction('IBLOCK_ELEMENT_ID', '\':\'', $propertyId),
					],
					'PRODUCT_ID' => [
						'FIELD_NAME' => self::MAIN_ALIAS . '.IBLOCK_ELEMENT_ID',
					],
					'PROPERTY_ID' => [
						'FIELD_NAME' => $propertyIdFieldName,
					],
					'VALUE' => [
						'FIELD_NAME' => $helper->castNumericToChar($propertyFieldName),
					],
					'VALUE_NAME' => ValueNameFields::forSingleValueColumn(
						$helper,
						self::MAIN_ALIAS,
						$propertyFieldName,
						$propertyId,
						$property['TYPE'],
					),
				],
			];
		}

		return $union;
	}

	/**
	 * @param BiSqlHelperInterface $helper SQL helper of the connection the dataset is assembled for.
	 * @param int $iblockId Iblock of version 2 whose multiple values the table holds.
	 * @param array<int, array{TYPE: string, MULTIPLE: bool, IBLOCK_ID: int}> $properties Properties of the catalog.
	 *
	 * @return array
	 */
	private static function getMultipleUnion(BiSqlHelperInterface $helper, int $iblockId, array $properties): array
	{
		return [
			'TABLE_NAME' => 'b_iblock_element_prop_m' . $iblockId,
			'TABLE_ALIAS' => self::MAIN_ALIAS,
			'FIELDS' => [
				'ID' => [
					'FIELD_NAME' => $helper->getConcatFunction(
						self::MAIN_ALIAS . '.IBLOCK_ELEMENT_ID',
						'\':\'',
						self::MAIN_ALIAS . '.IBLOCK_PROPERTY_ID',
						'\':\'',
						self::MAIN_ALIAS . '.ID',
					),
				],
				'PRODUCT_ID' => [
					'FIELD_NAME' => self::MAIN_ALIAS . '.IBLOCK_ELEMENT_ID',
				],
				'PROPERTY_ID' => [
					'FIELD_NAME' => self::MAIN_ALIAS . '.IBLOCK_PROPERTY_ID',
				],
				'VALUE' => [
					'FIELD_NAME' => self::MAIN_ALIAS . '.VALUE',
				],
				'VALUE_NAME' => ValueNameFields::forTextValueTable(
					$helper,
					self::MAIN_ALIAS,
					self::propertiesOfIblock($properties, $iblockId),
				),
			],
		];
	}
}
