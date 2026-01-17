<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Filter;

class PlacementFilterFactory
{
	public static function createDefaultFilterCollection(): PlacementFilterCollection
	{
		$collection = new PlacementFilterCollection();

		$collection->addFilter(new GroupIdPlacementFilter());

		return $collection;
	}
}
