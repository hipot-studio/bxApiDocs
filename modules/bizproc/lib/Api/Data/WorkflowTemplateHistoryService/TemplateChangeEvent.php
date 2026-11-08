<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService;

final class TemplateChangeEvent
{
	/**
	 * @param array<string, int> $summary how many changes of every TemplateChangeKind the event holds
	 * @param array[] $changesPreview first changes of the event, enough for a line in the feed
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $eventType,
		public readonly int $authorType,
		public readonly ?int $authorId,
		public readonly ?int $initiatorUserId,
		public readonly ?string $aiTool,
		public readonly ?string $requestSource,
		public readonly int $createdTimestamp,
		public readonly ?int $versionNumber,
		public readonly array $summary,
		public readonly bool $hasDetails,
		public readonly array $changesPreview = [],
	)
	{}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'eventType' => $this->eventType,
			'authorType' => $this->authorType,
			'authorId' => $this->authorId,
			'initiatorUserId' => $this->initiatorUserId,
			'aiTool' => $this->aiTool,
			'requestSource' => $this->requestSource,
			'createdTimestamp' => $this->createdTimestamp,
			'versionNumber' => $this->versionNumber,
			'summary' => $this->summary,
			'hasDetails' => $this->hasDetails,
			'changesPreview' => $this->changesPreview,
		];
	}
}
