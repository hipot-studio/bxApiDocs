<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;

final class CatalogItemOpenability
{
	public function isOpenable(CatalogItem $item): bool
	{
		return $item->getType() === CatalogItemType::Application
			&& trim((string)$item->getExternalId()) !== '';
	}
}
