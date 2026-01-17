<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Public\Command\Task\Convert\TemplateToTaskCommand;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class Convert extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Convert.template
	 */
	public function templateAction(
		#[Permission\Read]
		Entity\Template $template,
		TaskProvider $taskProvider,
	): ?Arrayable
	{
		$config = new AddConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new TemplateToTaskCommand(
			templateId: $template->getId(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(new TaskParams(taskId: $result->getId(), userId: $this->userId));
	}
}
