<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Timeman\V2\Internal\Entity\UserCollection;

interface UserServiceInterface
{
	public function getUsers(array $userIds): UserCollection;

	public function isExists(int $userId): bool;
}
