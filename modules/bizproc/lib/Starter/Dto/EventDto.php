<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter\Dto;

final class EventDto
{
	public function __construct(
		public readonly string $code,
		public readonly array $parameters = [],
		public readonly array $documents = [],
		public readonly int $eventType = \CBPDocumentEventType::Trigger,
		public readonly int $userId = 0,
	)
	{}
}
