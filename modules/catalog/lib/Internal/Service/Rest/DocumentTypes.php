<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\StoreDocumentTable;

final class DocumentTypes
{
	/**
	 * REST-available document types (Engine parity: without TYPE_UNDO_RESERVE).
	 *
	 * @return array<string, string>
	 */
	public static function getAvailable(): array
	{
		$types = StoreDocumentTable::getTypeList(true);
		unset($types[StoreDocumentTable::TYPE_UNDO_RESERVE]);

		return $types;
	}

	/**
	 * @return list<string>
	 */
	public static function getAvailableCodes(): array
	{
		return array_keys(self::getAvailable());
	}
}
