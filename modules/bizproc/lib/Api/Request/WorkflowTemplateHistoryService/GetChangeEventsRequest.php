<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService;

final class GetChangeEventsRequest
{
	public function __construct(
		public readonly int $templateId,
		public readonly ?int $userId = null,
		public readonly ?int $cursor = null,
		public readonly ?int $limit = null,
	)
	{}
}
