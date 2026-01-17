<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template\Access;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntityCollection;

class AccessEntityCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return AccessEntity::class;
	}
}
