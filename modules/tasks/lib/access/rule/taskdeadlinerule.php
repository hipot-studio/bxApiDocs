<?php

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Date;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\Model\UserModel;
use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Main\Error;

/**
 * @property TaskAccessController $controller
 * @property UserModel $user
 */
class TaskDeadlineRule extends AbstractRule
{
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

		if (array_intersect($item->getMembers(RoleDictionary::ROLE_DIRECTOR), $this->user->getAllSubordinates()))
		{
			return true;
		}

		$changeContext = null;
		if (
			$item->isMember($this->user->getUserId(), RoleDictionary::ROLE_RESPONSIBLE)
			|| array_intersect($item->getMembers(RoleDictionary::ROLE_RESPONSIBLE), $this->user->getAllSubordinates())
		)
		{
			$isAllowedChangeDeadline = $item->isAllowedChangeDeadline($this->user->getUserId(), $params);

			if ($isAllowedChangeDeadline)
			{
				return true;
			}

			$changeContext = $item->getDeadlineChangeContext();
		}
		$userErrors = $this->controller->getUserErrors();

		$canEdit = $this->controller->check(ActionDictionary::ACTION_TASK_EDIT, $item, $params);

		if (!$canEdit && $changeContext && $changeContext->isDateExceedsLimit && $changeContext->dateLimit)
		{
			$errorMessage = Loc::getMessage(
				'TASKS_ACCESS_DENIED_TO_DEADLINE_UPDATE_RESTRICTED_BY_DATE',
				[
					'#DATE#' => $changeContext->dateLimit->format(Date::getFormat()),
				],
			);
			$userErrors[] = new Error($errorMessage);
			$this->controller->clearUserErrors();
			foreach ($userErrors as $userError)
			{
				$this->controller->addUserError($userError);
			}
		}

		return $canEdit;
	}
}
