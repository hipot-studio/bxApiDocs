<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService;

use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeEntry;
use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeEvent;
use Bitrix\Bizproc\Result;

final class GetChangeEventResponse extends Result
{
	public function getEvent(): ?TemplateChangeEvent
	{
		return $this->data['event'] ?? null;
	}

	public function setEvent(TemplateChangeEvent $event): self
	{
		$this->data['event'] = $event;

		return $this;
	}

	/**
	 * @return TemplateChangeEntry[]
	 */
	public function getChanges(): array
	{
		return $this->data['changes'] ?? [];
	}

	/**
	 * @param TemplateChangeEntry[] $changes
	 */
	public function setChanges(array $changes): self
	{
		$this->data['changes'] = $changes;

		return $this;
	}
}
