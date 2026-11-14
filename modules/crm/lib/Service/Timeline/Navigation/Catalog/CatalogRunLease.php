<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Navigation\Catalog;

final class CatalogRunLease
{
	public function __construct(public readonly CatalogRunState $state)
	{
	}
}
