<?php

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService;

use Bitrix\Bizproc\Result;

final class RestoreVersionResponse extends Result
{
	/**
	 * @return int|null null means the template is left without drafts and the editor returns to the
	 * published configuration
	 */
	public function getDraftId(): ?int
	{
		return $this->data['draftId'] ?? null;
	}

	public function setDraftId(?int $draftId): self
	{
		$this->data['draftId'] = $draftId;

		return $this;
	}

	/**
	 * The revision the client has to come back with on its next write of the draft it is switched to. The
	 * service knows it from the row it has just written or read, so the caller never re-reads the draft.
	 */
	public function getDraftRevision(): int
	{
		return (int)($this->data['draftRevision'] ?? 0);
	}

	public function setDraftRevision(int $draftRevision): self
	{
		$this->data['draftRevision'] = $draftRevision;

		return $this;
	}
}
