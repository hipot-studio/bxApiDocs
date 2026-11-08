<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService;

use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeEvent;
use Bitrix\Bizproc\Result;

final class GetChangeEventsResponse extends Result
{
	/**
	 * @return TemplateChangeEvent[]
	 */
	public function getEvents(): array
	{
		return $this->data['events'] ?? [];
	}

	/**
	 * @param TemplateChangeEvent[] $events
	 */
	public function setEvents(array $events): self
	{
		$this->data['events'] = $events;

		return $this;
	}

	public function getNextCursor(): ?int
	{
		return $this->data['nextCursor'] ?? null;
	}

	public function setNextCursor(?int $cursor): self
	{
		$this->data['nextCursor'] = $cursor;

		return $this;
	}
}
