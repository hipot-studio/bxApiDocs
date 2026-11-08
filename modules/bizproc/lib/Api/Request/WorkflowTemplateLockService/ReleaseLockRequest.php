<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateLockService;

final class ReleaseLockRequest
{
	/**
	 * @param bool $isPortalAdmin whether the caller may release a lease of another user
	 */
	public function __construct(
		public readonly int $templateId,
		public readonly int $userId,
		public readonly bool $isPortalAdmin = false,
	)
	{}
}
