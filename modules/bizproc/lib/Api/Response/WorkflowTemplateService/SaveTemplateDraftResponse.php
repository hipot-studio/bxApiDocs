<?php

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateService;

use Bitrix\Bizproc\Result;

final class SaveTemplateDraftResponse extends Result
{
	public function getTemplateDraftId(): int
	{
		return $this->data['templateDraftId'] ?? 0;
	}

	public function setTemplateDraftId(int $templateDraftId): self
	{
		$this->data['templateDraftId'] = $templateDraftId;

		return $this;
	}

	/**
	 * The revision the draft carries after the write: the value the next write of the same client has to
	 * come back with.
	 */
	public function getDraftRevision(): int
	{
		return $this->data['draftRevision'] ?? 0;
	}

	public function setDraftRevision(int $draftRevision): self
	{
		$this->data['draftRevision'] = $draftRevision;

		return $this;
	}
}
