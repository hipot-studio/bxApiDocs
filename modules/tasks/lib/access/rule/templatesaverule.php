<?php

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TemplateModel;
use Bitrix\Tasks\Access\Model\UserModel;
use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Tasks\Access\Rule\Traits\AssignTrait;
use Bitrix\Tasks\Access\Rule\Traits\GroupTrait;
use Bitrix\Tasks\Access\TemplateAccessController;

/**
 * @property TemplateAccessController $controller
 */
class TemplateSaveRule extends AbstractRule
{
	use AssignTrait;
	use GroupTrait;

	/**
	 * @property TemplateAccessController $controller
	 */
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (
			!$item instanceof TemplateModel
			|| !$params instanceof TemplateModel
		)
		{
			$this->controller->addError(static::class, 'Incorrect template');
			return false;
		}

		$oldTemplate = $item;
		$newTemplate = $params;

		if (
			!$oldTemplate->getId()
			&& !$this->controller->check(ActionDictionary::ACTION_TEMPLATE_CREATE, $oldTemplate, $params)
		)
		{
			$this->controller->addError(static::class, 'Access to create or update template denied');

			return false;
		}

		if (
			!$this->controller->check(ActionDictionary::ACTION_TEMPLATE_EDIT, $oldTemplate, $params)
		)
		{
			$this->controller->addError(static::class, 'Access to create or update template denied');

			return false;
		}

		if (!$this->canAssignMembersExtranet($newTemplate, $oldTemplate))
		{
			$this->controller->addError(static::class, 'Access to assign extranet members denied');

			return false;
		}

		if (!$newTemplate->isRegular())
		{
			return true;
		}

		$members = $newTemplate->getMembers();

		$user = UserModel::createFromId($members[RoleDictionary::ROLE_DIRECTOR][0]);

		if (
			$newTemplate->getGroupId()
			&& $oldTemplate->getGroupId() !== $newTemplate->getGroupId()
			&& !$this->canSetGroup($user->getUserId(), $newTemplate->getGroupId())
		)
		{
			$this->controller->addError(static::class, 'Access to set group denied');

			return false;
		}

		$responsibleList = $members[RoleDictionary::ROLE_RESPONSIBLE] ?? [];
		foreach ($responsibleList as $responsibleId)
		{
			if (!$this->canAssign($user, $responsibleId, [], $item->getGroupId()))
			{
				$this->controller->addError(static::class, 'Access to assign responsible denied');

				return false;
			}
		}

		$accompliceList = $members[RoleDictionary::ROLE_ACCOMPLICE] ?? [];
		foreach ($accompliceList as $accompliceId)
		{
			if (!$this->canAssign($user, $accompliceId, [], $item->getGroupId()))
			{
				$this->controller->addError(static::class, 'Access to assign accomplice denied');

				return false;
			}
		}

		return true;
	}

	private function canAssignMembersExtranet(TemplateModel $newTemplate, TemplateModel $oldTemplate): bool
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			$this->controller->addError(static::class, 'Unable to load sonet');
			return false;
		}

		if (!Loader::includeModule('extranet'))
		{
			$this->controller->addError(static::class, 'Unable to load extranet');
		}

		$currentUser = UserModel::createFromId($this->user->getUserId());

		if (!$currentUser->isExtranet())
		{
			return true;
		}

		$memberIds = array_unique(
			array_merge(
				$this->getNewMembers(RoleDictionary::ROLE_ACCOMPLICE, $newTemplate, $oldTemplate),
				$this->getNewMembers(RoleDictionary::ROLE_RESPONSIBLE, $newTemplate, $oldTemplate),
				$this->getNewMembers(RoleDictionary::ROLE_AUDITOR, $newTemplate, $oldTemplate)
			)
		);

		foreach ($memberIds as $id)
		{
			if ($currentUser->getUserId() === $id)
			{
				continue;
			}
			if (!$this->isMemberOfUserGroups($currentUser->getUserId(), $id))
			{
				return false;
			}
		}
		return true;
	}

	private function getNewMembers(string $key, TemplateModel $newTemplate, TemplateModel $oldTemplate): array
	{
		return array_diff($newTemplate->getMembers($key), $oldTemplate->getMembers($key));
	}
}
