<?php

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TemplateModel;
use Bitrix\Tasks\Access\Permission\PermissionDictionary;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Tasks\Access\TemplateAccessController;

/**
 * @property TemplateAccessController $controller
 */
class TemplateEditRule extends AbstractRule
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

		if (!$item->getId())
		{
			return $this->controller->check(ActionDictionary::ACTION_TEMPLATE_CREATE, $item, $params);
		}

		if (!$this->controller->check(ActionDictionary::ACTION_TEMPLATE_READ, $item, $params))
		{
			$this->controller->addError(static::class, 'Access to template denied');

			return false;
		}

		if ($item->getTemplatePermission($this->user, PermissionDictionary::TEMPLATE_FULL))
		{
			return true;
		}

		$isInDepartment = $item->isInDepartment($this->user->getUserId(), false, [RoleDictionary::ROLE_DIRECTOR]);

		if (
			$isInDepartment
			&& $this->user->getPermission(PermissionDictionary::TEMPLATE_DEPARTMENT_EDIT)
		)
		{
			return true;
		}

		if (
			!$isInDepartment
			&& $this->user->getPermission(PermissionDictionary::TEMPLATE_NON_DEPARTMENT_EDIT)
		)
		{
			return true;
		}

		$this->controller->addError(static::class, 'Access to template edit denied');

		return false;
	}
}
