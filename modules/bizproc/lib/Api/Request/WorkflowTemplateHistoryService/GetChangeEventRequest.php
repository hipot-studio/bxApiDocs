<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService;

final class GetChangeEventRequest
{
	public function __construct(
		public readonly int $templateId,
		public readonly int $eventId,
		public readonly ?int $userId = null,
	)
	{}
}
