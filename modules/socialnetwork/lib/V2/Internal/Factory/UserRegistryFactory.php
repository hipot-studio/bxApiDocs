<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Factory;

use Bitrix\Socialnetwork\Internals\Registry\UserRegistry;

class UserRegistryFactory
{
	public function factory(int $userId): UserRegistry
	{
		return UserRegistry::getInstance($userId);
	}
}
