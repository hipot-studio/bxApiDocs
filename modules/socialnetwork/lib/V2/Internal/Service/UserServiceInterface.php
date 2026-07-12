<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Socialnetwork\V2\Internal\Entity\UserCollection;

interface UserServiceInterface
{
	public function getUsers(array $userIds): UserCollection;
}
