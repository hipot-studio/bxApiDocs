<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowTemplate\UpdateWorkflowTemplate;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\ORM\Data\UpdateResult;

class UpdateWorkflowTemplateCommand extends AbstractCommand
{
	/**
	 * @param list<string> $agentOwnedMetadataToRelease metadata fields the person takes back from the
	 *        ownership marker of the draft (AGT-TPL-01). Empty by default, and an empty list leaves this
	 *        command doing exactly what it did before the parameter existed - one write, no transaction.
	 */
	public function __construct(
		public readonly int $templateId,
		public readonly array $data,
		public readonly array $agentOwnedMetadataToRelease = [],
	)
	{
	}

	protected function execute(): UpdateResult
	{
		return (new UpdateWorkflowTemplateCommandHandler())($this);
	}
}
