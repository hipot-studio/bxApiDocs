<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Template\Convert;

use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Add\TemplateFromTaskCreator;

class ConvertToTemplateByTaskHandler
{
	public function __construct(
		private readonly TemplateFromTaskCreator $templateFromTaskCreator,
	)
	{
	}

	/**
	 * @throws TemplateAddException
	 */
	public function __invoke(ConvertToTemplateByTaskCommand $command): Entity\Template
	{
		$config = new AddConfig(
			userId: $command->userId,
			withReplication: $command->task->replicate ?? false,
			withCheckLists: false,
			withRelatedTasks: false,
		);

		return $this->templateFromTaskCreator->create(
			$command->task,
			$config,
		);
	}
}
