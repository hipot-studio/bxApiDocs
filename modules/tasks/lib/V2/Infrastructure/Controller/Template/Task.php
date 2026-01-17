<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Template;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Add\Config\AddTaskConfig;
use Bitrix\Tasks\V2\Public\Command\Template\Task\AddTaskCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\Task\TemplateToTaskParams;
use Bitrix\Tasks\V2\Public\Provider\Template\Task\TemplateToTaskProvider;

class Task extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Template.Task.get
	 */
	#[CloseSession]
	public function getAction(
		#[Permission\Read]
		Entity\Template $template,
		TemplateToTaskProvider $provider,
	): ?Entity\Task
	{
		return $provider->get(new TemplateToTaskParams(
			userId: $this->userId,
			templateId: $template->getId(),
		));
	}

	/**
	 * @ajaxAction tasks.V2.Template.Task.add
	 */
	public function addAction(
		#[Permission\Read]
		Entity\Template $template,
		Entity\Task $task,
		bool $withSubTasks = false,
	): ?Arrayable
	{
		$userId = $this->userId;
		$templateId = $template->getId();

		$result = (new AddTaskCommand(
			templateId: $templateId,
			taskData: $task,
			config: new AddTaskConfig(
				userId: $userId,
				withSubTasks: $withSubTasks,
			)
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getObject();
	}
}
