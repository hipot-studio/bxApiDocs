<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Service;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\UserUrlService;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;

class UserService
{
	public function __construct(
		private readonly UserRepositoryInterface $userRepository,
		private readonly UserUrlService $userUrlService,
	)
	{

	}

	public function getUsers(array $userIds): UserCollection
	{
		if (empty($userIds))
		{
			return new UserCollection();
		}

		return $this->userRepository->getByIds($userIds);
	}

	public function fillUser(UserCollection $userCollection, array $userIds): ?array
	{
		if (empty($userIds))
		{
			return null;
		}

		Collection::normalizeArrayValuesByInt($userIds, false);

		$users = [];
		foreach ($userIds as $userId)
		{
			$userEntity = $userCollection->findOneById($userId);

			if (!isset($userEntity))
			{
				continue;
			}

			$users[] = [
				'name' => $userEntity->name,
				'link' => $this->userUrlService->getDetailUrlByUserId($userEntity->id),
				'type' => $userEntity->type?->value,
			];
		}

		return $users;
	}
}
