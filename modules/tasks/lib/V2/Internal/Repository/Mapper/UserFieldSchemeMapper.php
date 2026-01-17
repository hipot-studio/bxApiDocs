<?php

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\Util\UserField;
use Bitrix\Tasks\V2\Internal\Entity\UserFieldScheme;
use Bitrix\Tasks\V2\Internal\Entity\UserFieldSchemeCollection;

class UserFieldSchemeMapper
{
	public function mapToCollection(array $scheme): UserFieldSchemeCollection
	{
		$entities = [];
		foreach ($scheme as $key => $value)
		{
			if (!is_string($key) || !str_starts_with($key, 'UF_'))
			{
				continue;
			}

			if (in_array($key, UserField::getSystemFields(), true))
			{
				continue;
			}

			$type = $value['USER_TYPE_ID'] ?? null;
			if (!in_array($type, $this->getAcceptedTypes(), true))
			{
				continue;
			}

			$entities[] = UserFieldScheme::mapFromArray($value);
		}

		return new UserFieldSchemeCollection(...$entities);
	}

	private function getAcceptedTypes(): array
	{
		return ['string', 'double', 'boolean', 'datetime'];
	}
}
