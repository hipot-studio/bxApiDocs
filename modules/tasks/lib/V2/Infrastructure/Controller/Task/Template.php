<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Public\Command\Task\Template\Convert\ConvertToTemplateByTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Template\Convert\ConvertToTemplateByTaskIdCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\TemplateParams;
use Bitrix\Tasks\V2\Public\Provider\Template\TemplateProvider;

class Template extends BaseController
{
	/**
	 * @ajaxMethod tasks.V2.Task.Template.add
	 */
	public function addAction(
		#[Permission\SaveAsTemplate]
		Entity\Task $task,
		TemplateProvider $templateProvider,
	): ?Entity\Template
	{
		$result = (new ConvertToTemplateByTaskIdCommand(
			taskId: $task->getId(),
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $templateProvider->get(new TemplateParams(templateId: $result->getId(), userId: $this->userId));
	}

	/**
	 * @ajaxMethod tasks.V2.Task.Template.addFromData
	 */
	public function addFromDataAction(
		Entity\Task $task,
		TemplateProvider $templateProvider,
	): ?Entity\Template
	{
		$result = (new ConvertToTemplateByTaskCommand(
			task: $task,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $templateProvider->get(new TemplateParams(templateId: $result->getId(), userId: $this->userId));
	}
}
