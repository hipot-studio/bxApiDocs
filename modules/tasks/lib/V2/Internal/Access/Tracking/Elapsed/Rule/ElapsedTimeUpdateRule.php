<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Tracking\Elapsed\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Tasks\V2\Internal\Access\Tracking\Elapsed\ElapsedTimeAccessController;
use Bitrix\Tasks\V2\Internal\Access\Tracking\Elapsed\ElapsedTimeModel;

/**
 * @property ElapsedTimeAccessController $controller
 */
class ElapsedTimeUpdateRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof ElapsedTimeModel)
		{
			$this->controller->addError(static::class, 'Invalid item type');

			return false;
		}

		if ($item->getId() <= 0)
		{
			$this->controller->addError(static::class, 'Elapsed time entry does not exist');

			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		if ($this->user->getUserId() === $item->getUserId())
		{
			return true;
		}

		$this->controller->addError(static::class, 'No permission to update elapsed time entry');

		return false;
	}
}
