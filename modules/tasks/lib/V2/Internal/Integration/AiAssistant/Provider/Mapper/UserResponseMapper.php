<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper;

use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

class UserResponseMapper
{
	public function mapFromEntityCollection(?UserCollection $users): ?array
	{
		if ($users === null)
		{
			return null;
		}

		return array_map(fn(User $user): array => $this->mapFromEntity($user), $users->getEntities());
	}

	public function mapFromEntity(?User $user): ?array
	{
		if ($user === null)
		{
			return null;
		}

		return [
			'id' => $user->getId(),
			'name' => $user->name,
		];
	}

	public function mapFromIdAndName(int|string|null $id, ?string $name): array
	{
		if ($id !== null)
		{
			$id = (int)$id;
		}

		return [
			'id' => $id,
			'name' => $name,
		];
	}
}
