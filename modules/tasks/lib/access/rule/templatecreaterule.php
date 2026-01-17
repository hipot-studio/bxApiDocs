<?php

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Tasks\Access\Model\TemplateModel;
use Bitrix\Tasks\Access\Permission\PermissionDictionary;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\Access\Rule\Traits\GroupTrait;
use Bitrix\Tasks\Access\TemplateAccessController;

/**
 * @property TemplateAccessController $controller
 */
class TemplateCreateRule extends AbstractRule
{
	use GroupTrait;

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

		/** @var null|TemplateModel $newTemplate */
		$newTemplate = $params;
		if (empty($newTemplate))
		{
			return (bool)$this->user->getPermission(PermissionDictionary::TEMPLATE_CREATE);
		}

		if (
			$newTemplate->getGroupId() > 0
			&& !$this->canSetGroup($this->user->getUserId(), $newTemplate->getGroupId())
		)
		{
			$this->controller->addError(static::class, 'Access to group denied');

			return false;
		}

		return (bool)$this->user->getPermission(PermissionDictionary::TEMPLATE_CREATE);
	}
}
