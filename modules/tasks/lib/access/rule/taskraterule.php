<?php

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Internals\Registry\FeaturePermRegistry;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\Model\UserModel;
use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\Access\TaskAccessController;

/**
 * @property TaskAccessController $controller
 * @property UserModel $user
 */
class TaskRateRule extends AbstractRule
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

		if ($item->isMember($this->user->getUserId(), RoleDictionary::ROLE_DIRECTOR))
		{
			return true;
		}

		if (
			$item->getGroupId()
			&& Loader::includeModule("socialnetwork")
			&& FeaturePermRegistry::getInstance()->get(
				$item->getGroupId(),
				'tasks',
				'edit_tasks',
				$this->user->getUserId()
			)
		)
		{
			return true;
		}

		if (array_intersect($item->getMembers(RoleDictionary::ROLE_DIRECTOR), $this->user->getAllSubordinates()))
		{
			return true;
		}

		$this->controller->addError(static::class, 'Access to rate task denied');

		return false;
	}
}
