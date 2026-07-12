<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Rest\Service;

use Bitrix\Main\Loader;
use Bitrix\Rest\PlacementTable;

class Placement
{
	public function getHandlersList(string $placement): array
	{
		if (!Loader::includeModule('rest'))
		{
			return [];
		}

		$handlers = PlacementTable::getHandlersList($placement);

		return is_array($handlers) ? $handlers : [];
	}
}
