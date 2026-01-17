<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\Task;

use Bitrix\Tasks\Control\Exception\TemplateNotFoundException;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Add\TaskFromTemplateCreator;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\TemplateParams;
use Bitrix\Tasks\V2\Public\Provider\Template\TemplateProvider;

class AddTaskHandler
{
	public function __construct(
		private readonly TemplateProvider $provider,
		private readonly TaskFromTemplateCreator $taskFromTemplateCreator,
	)
	{

	}

	public function __invoke(AddTaskCommand $command): Entity\Task
	{
		$template = $this->provider->get(new TemplateParams(
			templateId: $command->templateId,
			userId: $command->config->userId,
			group: false,
			members: false,
			checkLists: false,
			crm: false,
			tags: false,
			subTemplates: false,
			userFields: false,
			relatedTasks: false,
			permissions: false,
			parent: false,
			checkTemplateAccess: false,
		));

		if ($template === null)
		{
			throw new TemplateNotFoundException();
		}

		return $this->taskFromTemplateCreator->add($command->taskData, $template, $command->config);
	}
}
