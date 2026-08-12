<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Catalog;

use Bitrix\Main\Entity\EntityCollection;

/**
 * @method \ArrayIterator<int, CatalogItem> getIterator()
 */
final class CatalogItemCollection extends EntityCollection
{
	/** @return CatalogItem[] */
	public function toArray(): array
	{
		return $this->items;
	}

	protected static function getEntityClass(): string
	{
		return CatalogItem::class;
	}
}
