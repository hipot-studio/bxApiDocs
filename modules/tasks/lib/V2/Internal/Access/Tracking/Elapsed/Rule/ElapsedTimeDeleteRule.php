<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Tracking\Elapsed\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\Tracking\Elapsed\ElapsedTimeAccessController;

/**
 * @property ElapsedTimeAccessController $controller
 */
class ElapsedTimeDeleteRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		return $this->controller->check(ActionDictionary::ACTION_ELAPSED_TIME_UPDATE, $item, $params);
	}
}
