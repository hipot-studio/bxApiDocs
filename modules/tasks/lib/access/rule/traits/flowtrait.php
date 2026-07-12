<?php

namespace Bitrix\Tasks\Access\Rule\Traits;

use Bitrix\Tasks\Flow\Access\FlowModel;
use Bitrix\Tasks\Flow\FlowRegistry;
use Bitrix\Tasks\Access\AccessibleTask;
use Bitrix\Main\Loader;

trait FlowTrait
{
	private static array $flowModelCache = [];

	protected function checkFlowPermissions(int $flowId): bool
	{
		$flowModel = $this->getFlowModel($flowId);

		if ($flowModel === null)
		{
			return false;
		}

		if ($flowModel->isForAll())
		{
			return true;
		}

		if ($flowModel->isUserMember($this->user->getUserId()))
		{
			return true;
		}

		if ($flowModel->isInFlowDepartments($this->user->getUserId()))
		{
			return true;
		}

		return false;
	}

	/**
	 * @param AccessibleTask $oldTask
	 * @return bool
	 */

	private function canUserUpdateTaskAssigneeInFlow (AccessibleTask $oldTask, int $userId): bool
	{
		$flowId = $oldTask->getFlowId();

		if (
			$oldTask->getGroupId()
			&& $flowId
			&& Loader::includeModule("socialnetwork")
			&& $userId === FlowModel::createFromId($flowId)->getOwnerId()
		)
		{
			return true;
		}

		return false;
	}

	protected function doesGroupBelongToFlow(int $flowId, int $groupId): bool
	{
		if ($flowId <= 0 || $groupId <= 0)
		{
			return false;
		}

		$flowModel = $this->getFlowModel($flowId);

		if ($flowModel === null)
		{
			return false;
		}

		return $flowModel->getProjectId() === $groupId;
	}

	protected function getFlowModel(int $flowId): ?FlowModel
	{
		if (!isset(self::$flowModelCache[$flowId]))
		{
			$flow = FlowRegistry::getInstance()->get($flowId, ['*', 'MEMBERS']);

			self::$flowModelCache[$flowId] = $flow ? FlowModel::createFromArray($flow->toArray()) : null;
		}

		return self::$flowModelCache[$flowId];
	}
}
