<?php

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateService;

final class SaveTemplateRequest
{
	public function __construct(
		public readonly int $templateId,
		public readonly array $parameters,
		public readonly array $fields,
		public readonly \CBPWorkflowTemplateUser $user,
		public readonly bool $checkAccess = true,
		public readonly int $draftId = 0,
		public readonly ?int $draftRevision = null,
		public readonly bool $useEditConflictGuard = false,
	)
	{}
}
