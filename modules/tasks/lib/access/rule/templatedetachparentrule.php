<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TemplateModel;
use Bitrix\Tasks\Access\TemplateAccessController;

/**
 * @property TemplateAccessController $controller
 */
class TemplateDetachParentRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof TemplateModel)
		{
			$this->controller->addError(static::class, 'Incorrect template');

			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		$parentId = $item->getParentId();
		if ($parentId > 0 && !$this->controller->checkByItemId(ActionDictionary::ACTION_TEMPLATE_READ, $parentId))
		{
			$this->controller->addError(static::class, 'Access to parent template denied');

			return false;
		}

		if (!$this->controller->checkByItemId(ActionDictionary::ACTION_TEMPLATE_EDIT, $item->getId()))
		{
			$this->controller->addError(static::class, 'Access to detach parent template denied');

			return false;
		}

		return true;
	}
}
