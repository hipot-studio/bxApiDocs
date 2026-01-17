<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Entity;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

class WorkflowStartMessage extends AbstractMessage
{
	public function __construct(
		public readonly string $workflowId,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'workflowId' => $this->workflowId,
		];
	}
}
