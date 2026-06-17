<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Main\Type\Collection;
use Bitrix\Timeman\V2\Internal\Entity\UserCollection;
use Bitrix\Timeman\V2\Internal\Repository\FileRepositoryInterface;
use Bitrix\Timeman\V2\Internal\Repository\Mapper\UserMapper;
use Bitrix\Timeman\V2\Internal\Repository\UserRepositoryInterface;

class UserService implements UserServiceInterface
{
	public function __construct(
		private readonly UserRepositoryInterface $userRepository,
		private readonly FileRepositoryInterface $fileRepository,
		private readonly UserMapper $userMapper,
	)
	{
	}

	public function getUsers(array $userIds): UserCollection
	{
		if (empty($userIds))
		{
			return new UserCollection();
		}

		Collection::normalizeArrayValuesByInt($userIds, false);
		$userIds = array_values(array_unique($userIds));

		if (empty($userIds))
		{
			return new UserCollection();
		}

		$users = $this->userRepository->getByIds($userIds);

		if (empty($users))
		{
			return new UserCollection();
		}

		$fileIds = array_column($users, 'PERSONAL_PHOTO');
		Collection::normalizeArrayValuesByInt($fileIds, false);

		$files = $this->fileRepository->getByIds($fileIds);

		return $this->userMapper->mapToCollection($users, $files);
	}

	public function isExists(int $userId): bool
	{
		return $this->userRepository->isExists($userId);
	}
}
