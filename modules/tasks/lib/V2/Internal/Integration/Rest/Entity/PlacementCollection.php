<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Entity;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;

class PlacementCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Placement::class;
	}
}
