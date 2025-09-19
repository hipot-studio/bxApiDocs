<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\UserField;

use Bitrix\Intranet\Internal\Entity\IdentifiableEntityCollection;
use Bitrix\Intranet\Internal\Entity\UserField\UserField;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Entity\EntityInterface;

class UserFieldCollection extends IdentifiableEntityCollection
{
	protected static function getEntityClass(): string
	{
		return UserField::class;
	}
}
