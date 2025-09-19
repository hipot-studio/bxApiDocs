<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\UserProfile;

use Bitrix\Main\Entity\EntityCollection;

class GratitudeCollection extends EntityCollection
{
	protected static function getEntityClass(): string
	{
		return Gratitude::class;
	}
}
