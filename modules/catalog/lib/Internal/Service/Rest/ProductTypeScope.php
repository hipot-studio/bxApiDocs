<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\ProductTable;

/**
 * Product type policy of a REST V3 variation resource: the types its records may have
 * and the type the server writes. Holds data only: no request, DTO, rights or storage.
 */
final class ProductTypeScope
{
	/**
	 * @param list<int> $allowedTypes
	 */
	private function __construct(
		private readonly array $allowedTypes,
		private readonly int $writeType,
	)
	{
	}

	public static function sku(): self
	{
		return new self(
			[ProductTable::TYPE_SKU, ProductTable::TYPE_EMPTY_SKU],
			ProductTable::TYPE_EMPTY_SKU,
		);
	}

	public static function offer(): self
	{
		return new self(
			[ProductTable::TYPE_OFFER, ProductTable::TYPE_FREE_OFFER],
			ProductTable::TYPE_OFFER,
		);
	}

	public static function service(): self
	{
		return new self(
			[ProductTable::TYPE_SERVICE],
			ProductTable::TYPE_SERVICE,
		);
	}

	/**
	 * @return list<int>
	 */
	public function getAllowedTypes(): array
	{
		return $this->allowedTypes;
	}

	public function getWriteType(): int
	{
		return $this->writeType;
	}

	public function isAllowedType(int $type): bool
	{
		return in_array($type, $this->allowedTypes, true);
	}
}
