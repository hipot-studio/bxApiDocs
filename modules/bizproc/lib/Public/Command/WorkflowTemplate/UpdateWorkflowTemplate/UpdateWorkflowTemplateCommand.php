<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowTemplate\UpdateWorkflowTemplate;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\ORM\Data\UpdateResult;

class UpdateWorkflowTemplateCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $templateId,
		public readonly array $data,
	)
	{
	}

	protected function execute(): UpdateResult
	{
		return (new UpdateWorkflowTemplateCommandHandler())($this);
	}
}
