<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TemplateAccessController;

/**
 * @property TemplateAccessController $controller
 */
class TemplateAttachParentRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		return $this->controller->check(ActionDictionary::ACTION_TEMPLATE_DETACH_PARENT, $item, $params);
	}
}
