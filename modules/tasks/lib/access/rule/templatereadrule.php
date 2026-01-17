<?php

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Tasks\Access\Model\TemplateModel;
use Bitrix\Tasks\Access\Permission\PermissionDictionary;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Tasks\Access\TemplateAccessController;

/**
 * @property TemplateAccessController $controller
 */
class TemplateReadRule extends AbstractRule
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
			return true;
		}

		if (
			$item->getTemplatePermission($this->user, PermissionDictionary::TEMPLATE_VIEW)
			|| $item->getTemplatePermission($this->user, PermissionDictionary::TEMPLATE_FULL)
		)
		{
			return true;
		}

		$isInDepartment = $item->isInDepartment($this->user->getUserId(), false, [RoleDictionary::ROLE_DIRECTOR]);

		if (
			$isInDepartment
			&& $this->user->getPermission(PermissionDictionary::TEMPLATE_DEPARTMENT_VIEW)
		)
		{
			return true;
		}

		if (
			!$isInDepartment
			&& $this->user->getPermission(PermissionDictionary::TEMPLATE_NON_DEPARTMENT_VIEW)
		)
		{
			return true;
		}

		$this->controller->addError(static::class, 'Access to template denied');

		return false;
	}
}
