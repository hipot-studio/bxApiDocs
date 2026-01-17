<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template\Type;
use Bitrix\Tasks\V2\Internal\Entity\Template\TypeDictionary;

class TypeMapper
{
	public function mapToEnum(?int $type): ?Type
	{
		return match ($type) {
			TypeDictionary::NEW_USERS => Type::NewUsers,
			TypeDictionary::USUAL => Type::Usual,
			default => null,
		};
	}

	public function mapFromEnum(Type $type): ?int
	{
		return match($type)
		{
			Type::NewUsers => TypeDictionary::NEW_USERS,
			Type::Usual => TypeDictionary::USUAL,
			default => null,
		};
	}
}
