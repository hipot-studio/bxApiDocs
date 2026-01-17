<?php

namespace Bitrix\Crm\Security\Notification;

use Bitrix\Crm\CategoryIdentifier;
use Bitrix\Crm\Security\Notification\Queue\ReadPermissionAddMessage;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\UserAccessTable;

class PermissionNotificationManager
{
	use Singleton;

	public function notifyOnRoleRelationsChange(
		int $automatedSolutionId,
		CategoryIdentifier $categoryIdentifier,
		string $permType,
		array $newAccessCodeIds,
		array $oldAccessCodeIds = [],
	): void
	{
		$userIds = $this->getUserIdsByAccessCodes($newAccessCodeIds);

		if (empty($userIds))
		{
			return;
		}

		$currentUserIds = $this->getUserIdsByAccessCodes($oldAccessCodeIds);
		$userIds = array_diff($userIds, $currentUserIds);

		if (empty($userIds))
		{
			return;
		}

		$alreadyNotifiedUserIds = $this->getReceivedMessageUserIds($automatedSolutionId, $permType);
		$userIds = array_diff($userIds, $alreadyNotifiedUserIds);

		if (empty($userIds))
		{
			return;
		}

		$automatedSolution = Container::getInstance()
			->getAutomatedSolutionManager()
			->getAutomatedSolution($automatedSolutionId)
		;

		if ($automatedSolution === null)
		{
			return;
		}

		$url = Container::getInstance()->getRouter()->getItemListUrlInCurrentView(
			$categoryIdentifier->getEntityTypeId(),
			$categoryIdentifier->getCategoryId(),
		);

		$href = $url?->getUri() ?? '';
		$fromUserId = Container::getInstance()->getContext()->getUserId();

		foreach ($userIds as $userId)
		{
			$message = new ReadPermissionAddMessage(
				$automatedSolutionId,
				$automatedSolution['TITLE'],
				$href,
				$userId,
				$fromUserId,
			);

			$message->send('crm_role_permission_notification');
		}

		$this->logNotification($automatedSolutionId, $categoryIdentifier->getEntityTypeId(), $userIds, $permType);
	}

	private function getUserIdsByAccessCodes(array $newAccessCodeIds): array
	{
		if (empty($newAccessCodeIds))
		{
			return [];
		}

		$query = UserAccessTable::query()
			->setDistinct()
			->setSelect(['USER_ID'])
			->whereIn('ACCESS_CODE', $newAccessCodeIds)
		;

		return array_column($query->fetchAll(), 'USER_ID');
	}

	private function getReceivedMessageUserIds(int $automatedSolutionId, string $permType): array
	{
		$rows = EntityPermsNotificationTable::query()
			->setSelect(['USER_ID'])
			->where('AUTOMATED_SOLUTION_ID', $automatedSolutionId)
			->where('PERM_TYPE', $permType)
			->fetchAll()
		;

		return array_column($rows, 'USER_ID');
	}

	private function logNotification(
		int $automatedSolutionId,
		int $entityTypeId,
		array $userIds,
		string $permType,
	): void
	{
		$collection = new EO_EntityPermsNotification_Collection();

		foreach ($userIds as $userId)
		{
			$item = new EO_EntityPermsNotification();
			$item->setAutomatedSolutionId($automatedSolutionId);
			$item->setEntityTypeId($entityTypeId);
			$item->setUserId($userId);
			$item->setPermType($permType);

			$collection->add($item);
		}

		$collection->save(true);
	}
}
