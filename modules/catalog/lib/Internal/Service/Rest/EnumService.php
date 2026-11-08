<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Result;

/**
 * Static catalog enumerations for Rest V3: every method returns data key `items`
 * keyed by enumeration code.
 *
 * No access check by design: the enumerations stay open, as on the legacy transport.
 */
final readonly class EnumService
{
	/**
	 * @return Result with data key `items` as array<int, string>
	 */
	public function getProductTypes(): Result
	{
		return ServiceResult::success([
			'items' => ProductTable::getProductTypes(true),
		]);
	}

	/**
	 * @return Result with data key `items` as array<string, string>
	 */
	public function getDocumentTypes(): Result
	{
		return ServiceResult::success([
			'items' => DocumentTypes::getAvailable(),
		]);
	}

	/**
	 * Unlike the other enumerations this one has no label: every record is a pair of
	 * iblock property type and user type.
	 *
	 * @return Result with data key `items` as array<string, array{propertyType: string, userType: string|null}>
	 */
	public function getProductPropertyTypes(): Result
	{
		return ServiceResult::success([
			'items' => ProductPropertyTypes::getAvailable(),
		]);
	}
}
