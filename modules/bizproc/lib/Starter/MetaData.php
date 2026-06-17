<?php

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Workflow\Entity\EO_WorkflowMetadata;

final class MetaData
{
	private ?int $timeToStart;

	public function __construct(?int $timeToStart = null)
	{
		$this->timeToStart = $timeToStart;
	}

	public function saveToWorkflowId(string $workflowId): ?int
	{
		$hasDataToSave = false;

		$metadata = new EO_WorkflowMetadata();
		$metadata->setWorkflowId($workflowId);
		if ($this->timeToStart !== null)
		{
			$hasDataToSave = true;
			$metadata->setStartDuration($this->timeToStart);
		}

		if (!$hasDataToSave)
		{
			return null;
		}

		$saveResult = $metadata->save();

		return $saveResult->isSuccess() ? $metadata->getId() : null;
	}
}
