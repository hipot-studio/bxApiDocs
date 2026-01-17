<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Entity\User\Profile\BaseInfo;

class UserField extends EntityField
{
	protected function getEntityType() : string
	{
		return BaseInfo::class;
	}

	protected static function parseSingleValue(mixed $value): mixed
	{
		if ($value instanceof User)
		{
			return BaseInfo::createByUserEntity($value);
		}

		return $value;
	}
}
