<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\User;

use Bitrix\Intranet\Exception\WrongIdException;
use Bitrix\Intranet\Internal\Entity\User\Profile\BaseInfoCollection;
use Bitrix\Intranet\Internal\Entity\User\Profile\Profile;
use Bitrix\Intranet\Internal\Repository\User\Profile\ProfileRepository;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\ObjectNotFoundException;

class UserProfileProvider
{
	public function __construct(
		private ProfileRepository $userProfileRepository,
		private UserRepository $userRepository,
	)
	{}

	public static function createByDefault(): self
	{
		return new self(
			ProfileRepository::createByDefault(),
			new UserRepository(),
		);
	}

	/**
	 * @throws ObjectNotFoundException
	 * @throws WrongIdException
	 */
	public function getByUserId(int $userId): Profile
	{
		return $this->userProfileRepository->getById($userId);
	}

	public function getBaseInfoByUserIdList(array $userIds): BaseInfoCollection
	{
		return BaseInfoCollection::createByUserCollection(
			$this->userRepository->findUsersByIds($userIds)
		);
	}
}
