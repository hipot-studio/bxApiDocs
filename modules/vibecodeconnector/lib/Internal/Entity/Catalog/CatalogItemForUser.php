<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Catalog;

final class CatalogItemForUser
{
	public function __construct(
		public readonly CatalogItem $item,
		public readonly bool $isPinned,
		public readonly bool $isOwnedByUser,
		public readonly bool $isHidden = false,
	) {}
}
