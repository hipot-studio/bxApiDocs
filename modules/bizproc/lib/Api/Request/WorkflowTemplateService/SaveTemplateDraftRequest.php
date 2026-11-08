<?php

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateService;

use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeAttribution;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeRequestSource;

final class SaveTemplateDraftRequest
{
	/**
	 * @param int|null $revision revision of the draft the writer has seen; null skips only the content comparison
	 * while the conflict guard still protects the identity of the draft and its conditional write
	 * @param list<string> $fieldsToClaim metadata fields the writer declares its own (AGT-TPL-01), out of
	 * {@see \Bitrix\Bizproc\Workflow\Template\AgentOwnedMetadata}. Empty by default: a writer that claims
	 * nothing leaves the marker of the bound draft to the carry-over rule, exactly as before the parameter
	 * existed.
	 */
	public readonly TemplateChangeAttribution $attribution;
	public function __construct(
		public readonly int $templateId,
		public readonly array $parameters,
		public readonly array $fields,
		public readonly \CBPWorkflowTemplateUser $user,
		public readonly bool $checkAccess = true,
		public readonly ?int $draftId = null,
		public readonly ?int $revision = null,
		?TemplateChangeAttribution $attribution = null,
		public readonly bool $useEditConflictGuard = false,
		public readonly array $fieldsToClaim = [],
	)
	{
		$this->attribution = $attribution
			?? TemplateChangeAttribution::forUser($user->getId(), TemplateChangeRequestSource::Editor)
		;
	}
}
