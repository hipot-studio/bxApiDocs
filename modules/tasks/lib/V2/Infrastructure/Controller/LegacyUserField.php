<?php

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\Response\Component;
use Bitrix\Main\Error;
use Bitrix\Main\Security\Random;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
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
	public function getTaskAction(Entity\Task $task): ?Component
	{
		if ($task->getId() > 0)
		{
			$accessController = $this->getAccessController(Type::Task, $this->getAccessContext());

			if (!$accessController->checkByItemId(ActionDictionary::ACTION_TASK_READ, $task->getId()))
			{
				$this->addError(new Error('Access denied'));

				return null;
			}
		}

		$randomId = Random::getString(8);
		$taskData = $task->getId()
			? \Bitrix\Tasks\Manager\Task::get($this->userId, $task->getId())['DATA']
			: []
		;

		return new Component(
			'bitrix:tasks.userfield.panel',
			'',
			[
				'EXCLUDE' => UserField::TASK_SYSTEM_USER_FIELDS,
				'DATA' => $taskData,
				'ENTITY_CODE' => 'TASK',
				'SIGNATURE' => "bitrix-tasks-userfield-panel-$randomId",
				'INPUT_PREFIX' => 'USER_FIELDS',
				'RELATED_ENTITIES' => [
					'TASK_TEMPLATE',
				],
			],
			['HIDE_ICONS' => 'Y']
		);
	}

	/**
	 * @ajaxAction tasks.V2.LegacyUserField.getTemplate
	 */
	public function getTemplateAction(Entity\Template $template): ?Component
	{
		if ($template->getId() > 0)
		{
			$accessController = $this->getAccessController(Type::Template, $this->getAccessContext());

			if (!$accessController->checkByItemId(ActionDictionary::ACTION_TEMPLATE_READ, $template->getId()))
			{
				$this->addError(new Error('Access denied'));

				return null;
			}
		}

		$randomId = Random::getString(8);
		$templateData = $template->getId()
			? \Bitrix\Tasks\Manager\Task\Template::get($this->userId, $template->getId())['DATA']
			: []
		;

		return new Component(
			'bitrix:tasks.userfield.panel',
			'',
			[
				'EXCLUDE' => UserField::TASK_SYSTEM_USER_FIELDS,
				'DATA' => $templateData,
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
}
