<?php

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Util\UserField;
use Bitrix\Tasks\V2\Internal\Entity\UserFieldSchemeCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\UserFieldSchemeMapper;

class UserFieldSchemeRepository implements UserFieldSchemeRepositoryInterface
{
	public function __construct(
		private readonly UserFieldSchemeMapper $userFieldSchemeMapper,
	)
	{

	}

	public function getCollection(int $userId, string $entityCode): UserFieldSchemeCollection
	{
		$scheme = $this->getControllerClass($entityCode)::getScheme($entityCode, $userId);

		return $this->userFieldSchemeMapper->mapToCollection($scheme);
	}

	private function getControllerClass(string $entityCode): UserField
	{
		$className = UserField::getControllerClassByEntityCode($entityCode);

		return new $className();
	}
}
