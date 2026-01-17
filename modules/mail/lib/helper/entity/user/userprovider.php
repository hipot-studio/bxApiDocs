<?php

namespace Bitrix\Mail\Helper\Entity\User;

use Bitrix\Mail\Helper\Entity\BaseProvider;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserTable;

final class UserProvider extends BaseProvider
{
	private Tool $userTool;

	public function __construct()
	{
		$this->userTool = new Tool();
	}

	protected function createEntityInstance(array $entityData): User
	{
		$entityId = $entityData['ID'] ?? $entityData['id'];
		$photoId = $entityData['PERSONAL_PHOTO'] ?: 0;

		$userData['id'] = $entityId;
		$userData['avatar'] = $photoId ? $this->userTool->resizePhoto($photoId, 100, 100) : [];
		$userData['name'] = $this->userTool->formatName($entityData);
		$userData['pathToProfile'] = $this->userTool->getPathToProfile($entityId);
		$userData['position'] = $entityData['WORK_POSITION'];

		return new User($userData);
	}

	/**
	 * @param array $entityIds
	 * @return array{
	 *     ID: string,
	 *     NAME: string,
	 *     LAST_NAME: string,
	 *     LOGIN: string,
	 *     PERSONAL_PHOTO: string,
	 *     WORK_POSITION: string,
	 * }
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function getEntities(array $entityIds): array
	{
		$query =
			UserTable::query()
				->setSelect([
					'ID',
					'NAME',
					'LAST_NAME',
					'LOGIN',
					'PERSONAL_PHOTO',
					'WORK_POSITION',
				])
				->whereIn('ID', $entityIds)
		;

		return $query->fetchAll();
	}
}
