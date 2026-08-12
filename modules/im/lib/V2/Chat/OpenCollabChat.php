<?php

namespace Bitrix\Im\V2\Chat;

class OpenCollabChat extends CollabChat
{
	protected function getDefaultType(): string
	{
		return self::IM_TYPE_OPEN_COLLAB;
	}

	public function isAutoJoinEnabled(): bool
	{
		// Phase 0 (task 718250): keep open project chats relationless (no auto-join)
		return false;
	}

	public function getRecentSectionsForGuest(): array
	{
		return [];
	}

	public function filterUsersToMention(array $userIds): array
	{
		$result = [];
		$relations = $this->getRelationsByUserIds($userIds);

		foreach ($userIds as $userId)
		{
			$relation = $relations->getByUserId($userId, $this->getChatId());
			if (
				($relation === null
				|| $relation->getNotifyBlock())
				&& \CIMSettings::GetNotifyAccess($userId, 'im', 'mention', \CIMSettings::CLIENT_SITE)
			)
			{
				$result[$userId] = $userId;
			}
		}

		return $result;
	}

	protected function getAccessCodesForDiskFolder(): array
	{
		$accessCodes = parent::getAccessCodesForDiskFolder();
		$departmentCode = \Bitrix\Im\V2\Integration\HumanResources\Department\Department::getInstance()->getTopCode();

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
}
