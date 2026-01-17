<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage tasks
 * @copyright 2001-2021 Bitrix
 */

namespace Bitrix\Tasks\Rest\Controllers\Scrum;

use Bitrix\Main\UserTable;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Integration\SocialNetwork\Group;

trait UserTrait
{
	/**
	 * @param int $groupId
	 * @return bool
	 */
	private function checkAccess(int $groupId, int $taskId = 0): bool
	{
		$userId = $this->getUserId();

		$canReadGroupTasks = Group::canReadGroupTasks($userId, $groupId);
		if ($taskId && !$canReadGroupTasks)
		{
			$accessController = TaskAccessController::getInstance($userId);
			$model = TaskModel::createFromId($taskId);

			return $accessController->check(ActionDictionary::ACTION_TASK_READ, $model);
		}

		return $canReadGroupTasks;
	}

	private function existsUser(int $userId): bool
	{
		$queryObject = UserTable::getList([
			'select' => ['ID'],
			'filter' => ['ID' => $userId]
		]);

		return (bool) $queryObject->fetch();
	}
}