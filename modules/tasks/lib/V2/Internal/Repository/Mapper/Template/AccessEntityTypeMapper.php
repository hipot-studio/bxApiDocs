<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Tasks\V2\Internal\Entity\Template\Access\AccessEntityType;

class AccessEntityTypeMapper
{
	public function mapToEnum(string $type): ?AccessEntityType
	{
		return match ($type)
		{
			AccessCode::TYPE_SOCNETGROUP => AccessEntityType::Group,
			AccessCode::TYPE_DEPARTMENT => AccessEntityType::Department,
			AccessCode::TYPE_USER => AccessEntityType::User,
			default => null,
		};
	}
}
