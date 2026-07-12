<?php

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TemplateModel;
use Bitrix\Tasks\Access\Model\UserModel;
use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Tasks\Access\Rule\Traits\AssignTrait;
use Bitrix\Tasks\Access\Rule\Traits\GroupTrait;
use Bitrix\Tasks\Access\TaskAccessController;
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
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function execute(?AccessibleItem $item = null, $params = null): bool
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

		if (!$this->checkParentTemplate($oldTemplate, $newTemplate))
		{
			$this->controller->addError(static::class, 'Access to attach parent template denied');

			return false;
		}

		if (!$this->checkParentTask($oldTemplate, $newTemplate))
		{
			$this->controller->addError(static::class, 'Access to attach parent task denied');

			return false;
		}

		if (!$this->canAssignMembersByExtranetPolicy($oldTemplate, $newTemplate))
		{
			$this->controller->addError(static::class, 'Access to assign members denied by extranet policy');

			return false;
		}

		if (!$newTemplate->isRegular())
		{
			return true;
		}

		$members = $newTemplate->getMembers();
		$directorId = (int)($members[RoleDictionary::ROLE_DIRECTOR][0] ?? 0);

		if ($directorId <= 0)
		{
			$this->controller->addError(static::class, 'Incorrect director id');

			return false;
		}

		$director = $this->createUserModel($directorId);

		if (
			$newTemplate->getGroupId()
			&& $oldTemplate->getGroupId() !== $newTemplate->getGroupId()
			&& !$this->canSetGroup($directorId, $newTemplate->getGroupId())
		)
		{
			$this->controller->addError(static::class, 'Access to set group denied');

			return false;
		}

		$responsibleList = $members[RoleDictionary::ROLE_RESPONSIBLE] ?? [];
		foreach ($responsibleList as $responsibleId)
		{
			if (!$this->canAssign($director, $responsibleId, [], $item->getGroupId()))
			{
				$this->controller->addError(static::class, 'Access to assign responsible denied');

				return false;
			}
		}

		$accompliceList = $members[RoleDictionary::ROLE_ACCOMPLICE] ?? [];
		foreach ($accompliceList as $accompliceId)
		{
			if (!$this->canAssign($director, $accompliceId, [], $item->getGroupId()))
			{
				$this->controller->addError(static::class, 'Access to assign accomplice denied');

				return false;
			}
		}

		return true;
	}

	protected function canReadParentTask(int $parentTaskId): bool
	{
		return TaskAccessController::can($this->user->getUserId(), ActionDictionary::ACTION_TASK_READ, $parentTaskId);
	}

	private function checkParentTemplate(TemplateModel $oldTemplate, TemplateModel $newTemplate): bool
	{
		$newParentId = (int)$newTemplate->getParentId();

		if ($newParentId <= 0)
		{
			return true;
		}

		$oldParentId = (int)$oldTemplate->getParentId();

		if ($newParentId === $oldParentId)
		{
			return true;
		}

		return $this->controller->checkByItemId(ActionDictionary::ACTION_TEMPLATE_READ, $newParentId);
	}

	private function checkParentTask(TemplateModel $oldTemplate, TemplateModel $newTemplate): bool
	{
		$newParentTaskId = (int)$newTemplate->getParentTaskId();

		if ($newParentTaskId <= 0)
		{
			return true;
		}

		$oldParentTaskId = (int)$oldTemplate->getParentTaskId();

		if ($newParentTaskId === $oldParentTaskId)
		{
			return true;
		}

		return $this->canReadParentTask($newParentTaskId);
	}

	/**
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function canAssignMembersByExtranetPolicy(TemplateModel $oldTemplate, TemplateModel $newTemplate): bool
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			$this->controller->addError(static::class, 'Unable to load sonet');

			return false;
		}

		$currentUser = $this->createUserModel($this->user->getUserId());

		$addedMemberIds = $this->getAddedMemberIds($oldTemplate, $newTemplate);

		if ($currentUser->isExtranet())
		{
			return $this->canExtranetUserAssignMembers($currentUser->getUserId(), $addedMemberIds);
		}

		return $this->canIntranetUserAssignMembers($currentUser->getUserId(), $addedMemberIds);
	}

	protected function createUserModel(int $userId): UserModel
	{
		return UserModel::createFromId($userId);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function getAddedMemberIds(TemplateModel $oldTemplate, TemplateModel $newTemplate): array
	{
		$oldMembers = $oldTemplate->getMembers();
		$newMembers = $newTemplate->getMembers();

		$roles = RoleDictionary::getAvailableRoles();
		$result = [];

		foreach ($roles as $role)
		{
			$addedIds = array_diff(
				$newMembers[$role] ?? [],
				$oldMembers[$role] ?? [],
			);

			foreach ($addedIds as $id)
			{
				$id = (int)$id;

				if ($id > 0)
				{
					$result[$id] = true;
				}
			}
		}

		return array_keys($result);
	}

	private function canExtranetUserAssignMembers(int $userId, array $memberIds): bool
	{
		foreach ($memberIds as $memberId)
		{
			if ($userId === $memberId)
			{
				continue;
			}

			if (!$this->isMemberOfUserGroups($userId, $memberId))
			{
				return false;
			}
		}

		return true;
	}

	private function canIntranetUserAssignMembers(int $userId, array $memberIds): bool
	{
		foreach ($memberIds as $memberId)
		{
			if ($userId === $memberId)
			{
				continue;
			}

			$member = $this->createUserModel($memberId);

			if (
				$member->isExtranet()
				&& !$this->isMemberOfUserGroups($userId, $memberId)
			)
			{
				return false;
			}
		}

		return true;
	}
}
