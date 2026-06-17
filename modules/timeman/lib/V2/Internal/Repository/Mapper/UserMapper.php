<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository\Mapper;

use Bitrix\Extranet\Service\ServiceContainer;
use Bitrix\Main\Loader;
use Bitrix\Timeman\V2\Internal\Entity\File;
use Bitrix\Timeman\V2\Internal\Entity\FileCollection;
use Bitrix\Timeman\V2\Internal\Entity\User;
use Bitrix\Timeman\V2\Internal\Entity\User\Gender;
use Bitrix\Timeman\V2\Internal\Entity\User\Type;
use Bitrix\Timeman\V2\Internal\Entity\UserCollection;
use Bitrix\Timeman\V2\Internal\Service\NameService;
use Bitrix\Timeman\V2\Internal\Service\PhotoService;

class UserMapper
{
	public function __construct(
		private readonly PhotoService $photoService,
		private readonly NameService $nameService,
	)
	{
	}

	public function mapToCollection(array $users, ?FileCollection $files = null): UserCollection
	{
		$result = [];

		foreach ($users as $user)
		{
			$userId = (int)$user['ID'];
			$personalPhoto = !empty($user['PERSONAL_PHOTO'])
				? $files?->findOneById((int)$user['PERSONAL_PHOTO'])
				: null;

			$gender = $user['PERSONAL_GENDER'] ?? '';
			$email = is_string($user['EMAIL'] ?? null) ? $user['EMAIL'] : null;

			$result[] = new User(
				id: $userId,
				name: $this->nameService->format($user),
				type: $this->resolveUserType($user),
				image: $personalPhoto instanceof File
					? $this->photoService->resize($personalPhoto)
					: null,
				gender: Gender::tryFrom($gender) ?? Gender::Male,
				email: $email,
				externalAuthId: is_string($user['EXTERNAL_AUTH_ID'] ?? null) ? $user['EXTERNAL_AUTH_ID'] : null,
			);
		}

		return new UserCollection(...$result);
	}

	private function resolveUserType(array $user): Type
	{
		$userId = (int)$user['ID'];
		$hasDepartmentField = isset($user['UF_DEPARTMENT']) && is_array($user['UF_DEPARTMENT']);

		if ($hasDepartmentField)
		{
			$isExtranet = empty($user['UF_DEPARTMENT']);
		}
		else
		{
			$isExtranet = $this->isExtranetUser($userId);
		}

		if ($isExtranet && $this->isCollaberUser($userId))
		{
			return Type::Collaber;
		}

		return $isExtranet ? Type::Extranet : Type::Employee;
	}

	private function isExtranetUser(int $userId): bool
	{
		if (!Loader::includeModule('extranet'))
		{
			return false;
		}

		return \CExtranet::IsExtranetUser($userId);
	}

	private function isCollaberUser(int $userId): bool
	{
		if (!Loader::includeModule('extranet'))
		{
			return false;
		}

		return ServiceContainer::getInstance()->getCollaberService()->isCollaberById($userId);
	}
}
