<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\PushFormat;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Main\Loader;

class OpenChat extends GroupChat
{
	protected function getDefaultType(): string
	{
		return self::IM_TYPE_OPEN;
	}

	protected function checkAccessWithoutCaching(int $userId): bool
	{
		if (User::getInstance($userId)->isExtranet())
		{
			$relation = $this->withContextUser($userId)->getSelfRelation();

			return $relation !== null;
		}

		return true;
	}

	protected function getAccessCodesForDiskFolder(): array
	{
		$accessCodes = parent::getAccessCodesForDiskFolder();
		$departmentCode = \CIMDisk::GetTopDepartmentCode();

		if ($departmentCode)
		{
			$driver = \Bitrix\Disk\Driver::getInstance();
			$rightsManager = $driver->getRightsManager();
			$accessCodes[] = [
				'ACCESS_CODE' => $departmentCode,
				'TASK_ID' => $rightsManager->getTaskIdByName($rightsManager::TASK_READ)
			];
		}

		return $accessCodes;
	}

	public function extendPullWatch(): void
	{
		if (Loader::includeModule('pull'))
		{
			\CPullWatch::Add($this->getContext()->getUserId(), "IM_PUBLIC_{$this->getId()}", true);
		}
	}

	public function needToSendPublicPull(): bool
	{
		return true;
	}

	protected function updateStateAfterUsersAdd(array $usersToAdd): self
	{
		parent::updateStateAfterUsersAdd($usersToAdd);

		if (Loader::includeModule('pull'))
		{
			foreach ($usersToAdd as $userId)
			{
				\CPullWatch::Delete($userId, 'IM_PUBLIC_' . $this->getId());
			}
		}

		return $this;
	}
}