<?php

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\UserFieldSchemeCollection;

interface UserFieldSchemeRepositoryInterface
{
	public function getCollection(int $userId, string $entityCode): UserFieldSchemeCollection;
}
