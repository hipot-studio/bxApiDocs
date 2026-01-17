<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowTemplate\UpdateWorkflowTemplate;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\WorkflowTemplateRepository;
use Bitrix\Main\ORM\Data\UpdateResult;

class UpdateWorkflowTemplateCommandHandler
{
	private WorkflowTemplateRepository $repository;

	public function __construct()
	{
		$this->repository = Container::getWorkflowTemplateRepository();
	}

	public function __invoke(UpdateWorkflowTemplateCommand $command): UpdateResult
	{
		return $this->repository->updateTemplate($command->templateId, $command->data);
	}
}
