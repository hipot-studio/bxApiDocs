<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\V2\Internal\Entity\UserField;
use Bitrix\Tasks\V2\Internal\Entity\UserFieldCollection;

class UserFieldMapper
{
	public function mapToCollection(array $userFields): UserFieldCollection
	{
		$entities = [];

		foreach ($userFields as $key => $value)
		{
			if (!is_string($key) || !str_starts_with($key, 'UF_'))
			{
				continue;
			}

			// skip system fields
			if (in_array($key, \Bitrix\Tasks\Util\UserField::getSystemFields(), true))
			{
				continue;
			}

			$entities[] = $this->mapToEntity($key, $value);
		}

		return new UserFieldCollection(...$entities);
	}

	public function mapFromCollection(UserFieldCollection $userFields): array
	{
		$data = [];

		foreach ($userFields as $userField)
		{
			$data[]= $userField->toArray();
		}

		return $data;
	}

	public function mapToEntity(string $field, mixed $value): ?UserField
	{
		return new UserField($field, $value);
	}

	public function mapFromEntity(UserField $userField): array
	{
		return [$userField->key => $userField->value];
	}
}
