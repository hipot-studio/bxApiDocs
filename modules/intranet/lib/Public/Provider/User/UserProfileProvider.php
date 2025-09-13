<?php

namespace Bitrix\Intranet\Public\Provider\User;

use Bitrix\Intranet\Exception\WrongIdException;
use Bitrix\Intranet\Internal\Entity\UserProfile;
use Bitrix\Intranet\Internal\Repository\UserProfileRepository;
use Bitrix\Main\ObjectNotFoundException;

class UserProfileProvider
{
	public function __construct(
		private UserProfileRepository $userProfileRepository,
	) {}

	public static function createByDefault(): UserProfileProvider
	{
		return new UserProfileProvider(
			new UserProfileRepository(),
		);
	}

	/**
	 * @throws ObjectNotFoundException
	 * @throws WrongIdException
	 */
	public function getByUserId(int $userId): UserProfile
	{
		return $this->userProfileRepository->getById($userId);
	}

	/**
	 * @throws WrongIdException
	 */
	public function getByUserFieldsArray(array $userFields): UserProfile
	{
		return $this->userProfileRepository->getByUserProfileArray($userFields);
	}
}
