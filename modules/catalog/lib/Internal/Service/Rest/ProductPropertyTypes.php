<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Iblock\PropertyTable;

/**
 * Rest V3 copy of the product property type enumeration.
 *
 * Deliberately a copy, not a reference to the Engine controller: the legacy transport
 * keeps its own contract. Divergence is guarded by ProductPropertyTypesParityTest.
 */
final class ProductPropertyTypes
{
	/** Catalog-owned user type, it has no counterpart among PropertyTable constants. */
	public const USER_TYPE_BOOL_ENUM = 'BoolEnum';

	/**
	 * @return array<string, array{propertyType: string, userType: string|null}>
	 */
	public static function getAvailable(): array
	{
		return [
			'NUMBER' => [
				'propertyType' => PropertyTable::TYPE_NUMBER,
				'userType' => null,
			],
			'STRING' => [
				'propertyType' => PropertyTable::TYPE_STRING,
				'userType' => null,
			],
			'LIST' => [
				'propertyType' => PropertyTable::TYPE_LIST,
				'userType' => null,
			],
			'BOOL_ENUM' => [
				'propertyType' => PropertyTable::TYPE_LIST,
				'userType' => self::USER_TYPE_BOOL_ENUM,
			],
			'DATETIME' => [
				'propertyType' => PropertyTable::TYPE_STRING,
				'userType' => PropertyTable::USER_TYPE_DATETIME,
			],
			'MONEY' => [
				'propertyType' => PropertyTable::TYPE_STRING,
				'userType' => PropertyTable::USER_TYPE_MONEY,
			],
			'SKU' => [
				'propertyType' => PropertyTable::TYPE_ELEMENT,
				'userType' => PropertyTable::USER_TYPE_SKU,
			],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function getAvailableCodes(): array
	{
		return array_keys(self::getAvailable());
	}
}
