<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Factory;

use Bitrix\Intranet\Entity\User;

class UserFactory
{
	public function createEmptyFromId(int $userId): User
	{
		return new User(
			id: $userId
		);
	}
}
