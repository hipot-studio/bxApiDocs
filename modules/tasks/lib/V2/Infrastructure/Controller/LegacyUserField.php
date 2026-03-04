<?php

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\Response\Component;
use Bitrix\Main\Error;
use Bitrix\Main\Security\Random;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Manager;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity;

class LegacyUserField extends BaseController
{
	use AccessControllerTrait;

	/**
	 * @ajaxAction tasks.V2.LegacyUserField.getTask
	 */
	#[CloseSession]
	public function getTaskAction(
		Entity\Task $task,
	): ?Component
	{
		$taskId = (int)$task->getId();

		if (!$this->checkTaskAccess($taskId))
		{
			$this->addError(new Error('Access denied'));

			return null;
		}

		$taskData = $taskId ? Manager\Task::get($this->userId, $taskId)['DATA'] : [];

		return $this->getTaskUfComponent($taskData);
	}

	/**
	 * @ajaxAction tasks.V2.LegacyUserField.getTemplate
	 */
	#[CloseSession]
	public function getTemplateAction(
		Entity\Template $template,
	): ?Component
	{
		$templateId = (int)$template->getId();

		if (!$this->checkTemplateAccess($templateId))
		{
			$this->addError(new Error('Access denied'));

			return null;
		}

		$templateData = $templateId ? Manager\Task\Template::get($this->userId, $templateId)['DATA'] : [];

		return $this->getTemplateUfComponent($templateData);
	}

	/**
	 * @ajaxAction tasks.V2.LegacyUserField.getTaskFromTemplate
	 */
	#[CloseSession]
	public function getTaskFromTemplateAction(
		Entity\Template $template,
	): ?Component
	{
		$templateId = (int)$template->getId();

		if (!$this->checkTemplateAccess($templateId))
		{
			$this->addError(new Error('Access denied'));

			return null;
		}

		$templateData = $templateId ? Manager\Task\Template::get($this->userId, $templateId)['DATA'] : [];

		return $this->getTaskUfComponent($templateData);
	}

	private function getTaskUfComponent(array $data): Component
	{
		$randomId = Random::getString(8);

		return new Component(
			'bitrix:tasks.userfield.panel',
			'',
			[
				'EXCLUDE' => Entity\UF\UserField::TASK_SYSTEM_USER_FIELDS,
				'DATA' => $data,
				'ENTITY_CODE' => 'TASK',
				'SIGNATURE' => "bitrix-tasks-userfield-panel-$randomId",
				'INPUT_PREFIX' => 'USER_FIELDS',
				'RELATED_ENTITIES' => [
					'TASK_TEMPLATE',
				],
			],
			['HIDE_ICONS' => 'Y'],
		);
	}

	private function getTemplateUfComponent(array $data): Component
	{
		$randomId = Random::getString(8);

		return new Component(
			'bitrix:tasks.userfield.panel',
			'',
			[
				'EXCLUDE' => Entity\UF\UserField::TASK_SYSTEM_USER_FIELDS,
				'DATA' => $data,
				'ENTITY_CODE' => 'TASK_TEMPLATE',
				'SIGNATURE' => "bitrix-tasks-userfield-panel-template-$randomId",
				'INPUT_PREFIX' => 'USER_FIELDS',
				'RELATED_ENTITIES' => [
					'TASK',
				],
			],
			['HIDE_ICONS' => 'Y']
		);
	}

	private function checkTaskAccess(int $taskId): bool
	{
		if ($taskId > 0)
		{
			$accessController = $this->getAccessController(Type::Task, $this->getAccessContext());

			if (!$accessController->checkByItemId(ActionDictionary::ACTION_TASK_READ, $taskId))
			{
				return false;
			}
		}

		return true;
	}

	private function checkTemplateAccess(int $templateId): bool
	{
		if ($templateId > 0)
		{
			$accessController = $this->getAccessController(Type::Template, $this->getAccessContext());

			if (!$accessController->checkByItemId(ActionDictionary::ACTION_TEMPLATE_READ, $templateId))
			{
				return false;
			}
		}

		return true;
	}
}
