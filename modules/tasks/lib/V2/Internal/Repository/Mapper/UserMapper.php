<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\Integration\Extranet;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Service\NameService;
use Bitrix\Tasks\V2\Internal\Service\PhotoService;
use Bitrix\Tasks\V2\Internal\Entity;

class UserMapper
{
	public function __construct(
		private readonly PhotoService $photoService,
		private readonly NameService $nameService
	)
	{

	}

	public function mapToCollection(
		array $users,
		?Entity\FileCollection $files = null
	): Entity\UserCollection
	{
		$result = [];

		foreach ($users as $user)
		{
			$userId = (int)$user['ID'];
			$personalPhoto = $files?->findOneById((int)$user['PERSONAL_PHOTO']);
			$gender = $user['PERSONAL_GENDER'] ?? '';
			$email = is_string($user['EMAIL'] ?? null) ? $user['EMAIL'] : null;

			$result[] = new Entity\User(
				id: $userId,
				name: $this->nameService->format($user),
				type: $this->getUserType($userId),
				image: $personalPhoto ? $this->photoService->resize($personalPhoto) : null,
				gender: Entity\User\Gender::tryFrom($gender) ?? Entity\User\Gender::Male,
				email: $email,
			);
		}

		return new UserCollection(...$result);
	}

	private function getUserType(int $userId): Entity\User\Type
	{
		$isExtranet = Extranet\User::isExtranet($userId);
		$isCollaber = Extranet\User::isExtranet($userId) && Extranet\User::isCollaber($userId);

		if ($isCollaber)
		{
			return Entity\User\Type::Collaber;
		}

		return $isExtranet ? Entity\User\Type::Extranet : Entity\User\Type::Employee;
	}
}
