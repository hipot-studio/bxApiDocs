<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Integration\SocialNetwork\Group;

/**
 * @property TaskAccessController $controller
 */
class TaskSortRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof TaskModel)
		{
			$this->controller->addError(static::class, 'Incorrect task');

			return false;
		}

		if ($item->getGroupId() <= 0)
		{
			$this->controller->addError(static::class, 'Not in group');

			return false;
		}

		if (!Loader::includeModule('socialnetwork'))
		{
			$this->controller->addError(static::class, 'Socialnetwork module is not installed');

			return false;
		}

		if (!Group::can($item->getGroupId(), Group::ACTION_SORT_TASKS))
		{
			$this->controller->addError(static::class, 'Access to sort tasks in group denied');

			return false;
		}

		return true;
	}
}
