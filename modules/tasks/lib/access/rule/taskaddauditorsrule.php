<?php

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\Model\UserModel;
use Bitrix\Tasks\Access\Rule\Traits\AssignTrait;
use Bitrix\Tasks\Access\TaskAccessController;

/**
 * @property TaskAccessController $controller
 */
class TaskAddAuditorsRule extends AbstractRule
{
	use AssignTrait;

	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof TaskModel)
		{
			$this->controller->addError(static::class, 'Incorrect task');

			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		if ($item->getId() > 0 && !$this->controller->checkByItemId(ActionDictionary::ACTION_TASK_READ, $item->getId()))
		{
			$this->controller->addError(static::class, 'Access to read task denied');
			$this->controller->addUserError(new Error(Loc::getMessage('TASKS_TASK_ADD_AUDITORS_RULE_NO_READ_PERMISSIONS')));

			return false;
		}

		if (!$this->canAssignAuditors($params))
		{
			return false;
		}

		return true;
	}

	/**
	 * @param $auditors
	 * @return bool
	 */
	private function canAssignAuditors($auditors): bool
	{
		if (empty($auditors))
		{
			return true;
		}

		if (!is_array($auditors))
		{
			$auditors = [$auditors];
		}

		$currentUser = UserModel::createFromId($this->user->getUserId());
		$currentExtranet = $currentUser->isExtranet();

		foreach ($auditors as $auditorId)
		{
			$auditorId = (int)$auditorId;
			$auditor = UserModel::createFromId($auditorId);

			// always can assign to email users
			if ($auditor->isEmail())
			{
				continue;
			}

			if (
				!$currentExtranet
				&& !$auditor->isExtranet()
			)
			{
				continue;
			}

			if ($currentUser->getUserId() === $auditorId)
			{
				continue;
			}

			if (!$this->isMemberOfUserGroups($currentUser->getUserId(), $auditorId))
			{
				$this->controller->addError(static::class, 'Unable to add auditor from extranet.');
				$this->controller->addUserError(new Error(Loc::getMessage('TASKS_TASK_ADD_AUDITORS_RULE_NO_EXTRANET_PERMISSIONS')));

				return false;
			}
		}

		return true;
	}
}
