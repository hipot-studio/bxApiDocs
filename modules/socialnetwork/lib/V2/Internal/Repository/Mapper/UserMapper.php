<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository\Mapper;

use Bitrix\Socialnetwork\V2\Internal\Entity\File;
use Bitrix\Socialnetwork\V2\Internal\Entity\FileCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\User;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Gender;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Type;
use Bitrix\Socialnetwork\V2\Internal\Entity\UserCollection;
use Bitrix\Socialnetwork\V2\Internal\Integration\Extranet\Service\ExtranetUserService;
use Bitrix\Socialnetwork\V2\Internal\Service\NameService;
use Bitrix\Socialnetwork\V2\Internal\Service\PhotoService;

class UserMapper
{
	public function __construct(
		private readonly PhotoService $photoService,
		private readonly NameService $nameService,
		private readonly ExtranetUserService $extranetUserService,
	)
	{
	}

	public function mapToCollection(array $users, ?FileCollection $files = null): UserCollection
	{
		if (empty($users))
		{
			return new UserCollection();
		}

		$result = [];
		$filesById = $this->indexFilesById($files);
		$collaberIds = array_fill_keys($this->extranetUserService->getCollaberIds(), true);

		foreach ($users as $user)
		{
			$userId = (int)$user['ID'];
			$personalPhoto = !empty($user['PERSONAL_PHOTO'])
				? ($filesById[(int)$user['PERSONAL_PHOTO']] ?? null)
				: null;

			$gender = $user['PERSONAL_GENDER'] ?? '';
			$email = is_string($user['EMAIL'] ?? null) ? $user['EMAIL'] : null;

			$result[] = new User(
				id: $userId,
				name: $this->nameService->format($user),
				type: $this->resolveUserType($user, $collaberIds),
				image: $personalPhoto instanceof File
					? $this->photoService->resize($personalPhoto)
					: null,
				gender: Gender::tryFrom($gender) ?? Gender::Male,
				email: $email,
			);
		}

		return new UserCollection(...$result);
	}

	private function indexFilesById(?FileCollection $files): array
	{
		$result = [];

		foreach ($files ?? [] as $file)
		{
			if ($file->id !== null && !isset($result[$file->id]))
			{
				$result[$file->id] = $file;
			}
		}

		return $result;
	}

	private function resolveUserType(array $user, array $collaberIds): Type
	{
		$userId = (int)$user['ID'];

		if (isset($collaberIds[$userId]))
		{
			return Type::Collaber;
		}

		$hasDepartmentField = isset($user['UF_DEPARTMENT']) && is_array($user['UF_DEPARTMENT']);
		if ($hasDepartmentField && empty($user['UF_DEPARTMENT']))
		{
			return Type::Extranet;
		}

		return Type::Employee;
	}
}
