<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Public\Command\Task\Convert\TemplateToTaskCommand;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;

class Convert extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Convert.template
	 */
	public function templateAction(
		#[Permission\Read] Entity\Template $template,
		LinkService $linkService,
		TaskRightService $taskRightService,
	): ?Arrayable
	{
		$result = (new TemplateToTaskCommand(
			templateId: $template->getId(),
			config: new AddConfig($this->userId),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		/** @var Entity\Task $savedTask */
		$savedTask = $result->getObject();

		$rights = $taskRightService->get(ActionDictionary::TASK_ACTIONS, $savedTask->getId(), $this->userId);
		$link = $linkService->get($savedTask, $this->userId);

		return $savedTask->cloneWith(['link' => $link, 'rights' => $rights]);
	}
}
