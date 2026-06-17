<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Document\Result;

use Bitrix\Bizproc\Internal\Service\Document\Dto\ResolvedDocumentCollectionDto;
use Bitrix\Bizproc\Result;

final class ResolveDocumentsResult extends Result
{
	public function setDocuments(ResolvedDocumentCollectionDto $documents): self
	{
		$this->data['documents'] = $documents;

		return $this;
	}

	public function getDocuments(): ?ResolvedDocumentCollectionDto
	{
		$documents = $this->data['documents'] ?? null;

		return $documents instanceof ResolvedDocumentCollectionDto ? $documents : null;
	}
}
