<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateLockService;

final class AcquireLockRequest
{
	public function __construct(
		public readonly int $templateId,
		public readonly int $userId,
	)
	{}
}
