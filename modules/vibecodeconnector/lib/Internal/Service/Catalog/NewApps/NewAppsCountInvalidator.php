<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;

final class NewAppsCountInvalidator
{
	public function __construct(
		private readonly NewAppsCountCache $cache = new NewAppsCountCache(),
	) {}

	public function forgetAllFor(CatalogItem $item): void
	{
		if ($item->getAccessType() === CatalogItemAccessType::Private)
		{
			return;
		}

		$this->forgetAllForAccessTypeChangeOf($item);
	}

	public function forgetAllForAccessTypeChangeOf(CatalogItem $item): void
	{
		if ($item->getType() !== CatalogItemType::Application)
		{
			return;
		}

		$this->cache->forgetAll();
	}
}
