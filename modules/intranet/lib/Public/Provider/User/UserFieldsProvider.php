<?php

namespace Bitrix\Intranet\Public\Provider\User;

use Bitrix\Intranet\Internal\Entity\UserField\UserFieldCollection;
use Bitrix\Intranet\Internal\Repository\UserProfileRepository;

class UserFieldsProvider
{
	public function __construct(
		private UserProfileRepository $userProfileRepository,
	) {}

	public static function createByDefault(): UserFieldsProvider
	{
		return new UserFieldsProvider(
			new UserProfileRepository(),
		);
	}

	public function getByUserData(array $userData): UserFieldCollection
	{
		return $this->userProfileRepository->getUserFieldsByUserData($userData);
	}
}
