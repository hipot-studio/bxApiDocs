<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\LegacyGroup;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntityCollection;

class LegacyGroupCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return LegacyGroup::class;
	}
}
