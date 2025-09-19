<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity;

use Bitrix\Intranet\Internal\Entity\IdentifiableEntityCollection;
use Bitrix\Intranet\Internal\Entity\UserBaseInfo;

class UserBaseInfoCollection extends IdentifiableEntityCollection
{
	protected static function getEntityClass(): string
	{
		return UserBaseInfo::class;
	}
}
