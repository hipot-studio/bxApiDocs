<?php

namespace Bitrix\Tasks\Flow\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TemplateAccessController;
use Bitrix\Tasks\Flow\Access\FlowAccessController;
use Bitrix\Tasks\Flow\Access\FlowAction;
use Bitrix\Tasks\Flow\Access\FlowModel;
use Bitrix\Tasks\Flow\Access\ValidationTrait;
use Bitrix\Tasks\Integration\SocialNetwork\Group;
use Bitrix\Tasks\Internals\Registry\GroupRegistry;

class FlowSaveRule extends AbstractRule
{
	use ValidationTrait;

	/** @var FlowAccessController */
	protected $controller;

	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if ($item !== null && !$this->checkModel($item))
		{
			return false;
		}

		if (!$this->checkModel($params))
		{
			return false;
		}

		if (!$this->checkFlowPermission($item, $params))
		{
			return false;
		}

		/** @var ?FlowModel $item */
		$oldGroupId = (int)$item?->getProjectId();

		$newGroupId = $params->getProjectId();

		$isGroupChanged = $oldGroupId !== $newGroupId;

		if ($isGroupChanged && !$this->checkGroupAvailability($newGroupId))
		{
			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		if ($isGroupChanged && !$this->checkGroupPermission($newGroupId))
		{
			return false;
		}

		if (!$this->checkTemplatePermission($params->getTemplateId()))
		{
			return false;
		}

		return true;
	}

	private function checkFlowPermission(?FlowModel $item, FlowModel $params): bool
	{
		if ($params->isNew())
		{
			return $this->controller->check(FlowAction::CREATE, $params);
		}

		return $this->controller->check(FlowAction::UPDATE, $item, $params);
	}

	private function checkGroupAvailability(int $groupId): bool
	{
		if ($groupId <= 0)
		{
			$this->controller->addError(FlowSaveRule::class, 'Unable by invalid group id');

			return false;
		}

		$group = $this->getGroupRegistry()->get($groupId);
		if ($group === null)
		{
			$this->controller->addError(FlowSaveRule::class, 'Unable to load group info');

			return false;
		}

		$isTasksEnabled = $group['TASKS_ENABLED'] ?? null;
		$isClosed = ($group['CLOSED'] ?? null) === 'Y';

		if (!$isTasksEnabled || $isClosed)
		{
			$this->controller->addError(FlowSaveRule::class, 'Unable to create flow bc group is closed or tasks disabled');

			return false;
		}

		return true;
	}

	private function checkGroupPermission(int $groupId): bool
	{
		if (!$this->isUserGroupMember($groupId))
		{
			$this->controller->addError(FlowSaveRule::class, 'Unable by target group permissions');

			return false;
		}

		return true;
	}

	private function checkTemplatePermission(int $templateId): bool
	{
		if ($templateId <= 0)
		{
			return true;
		}

		if (!$this->canReadTemplate($templateId))
		{
			$this->controller->addError(FlowSaveRule::class, 'Unable to create flow by template permissions');

			return false;
		}

		return true;
	}

	protected function getGroupRegistry(): GroupRegistry
	{
		return GroupRegistry::getInstance();
	}

	protected function isUserGroupMember(int $groupId): bool
	{
		return Group::isUserMember($groupId, $this->user->getUserId());
	}

	protected function canReadTemplate(int $templateId): bool
	{
		return TemplateAccessController::can(
			userId: $this->user->getUserId(),
			action: ActionDictionary::ACTION_TEMPLATE_READ,
			itemId: $templateId,
		);
	}
}
