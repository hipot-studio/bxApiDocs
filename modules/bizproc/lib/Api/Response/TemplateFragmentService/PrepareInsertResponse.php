<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateFragmentService;

use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Workflow\Template\Fragment\Enum\SkipReason;

/**
 * A fragment ready to be put on the canvas (DTO-02): the blocks and the connections in the very shape the
 * loading of a scheme hands them over in, plus the two lists the user is told about - what never made it
 * and what has to be tuned by hand.
 *
 * A fragment every node of which was skipped is a success and not a refusal: the answer carries no block,
 * the whole of the fragment in the skipped list, and the client shows a warning over a canvas it left
 * untouched.
 */
final class PrepareInsertResponse extends Result
{
	public function getBlocks(): array
	{
		return $this->data['blocks'] ?? [];
	}

	public function setBlocks(array $blocks): self
	{
		$this->data['blocks'] = $blocks;

		return $this;
	}

	public function getConnections(): array
	{
		return $this->data['connections'] ?? [];
	}

	public function setConnections(array $connections): self
	{
		$this->data['connections'] = $connections;

		return $this;
	}

	/**
	 * @return list<array{nodeName: string, title: string, reason: SkipReason}>
	 */
	public function getSkipped(): array
	{
		return $this->data['skipped'] ?? [];
	}

	/**
	 * @param list<array{nodeName: string, title: string, reason: SkipReason}> $skipped
	 */
	public function setSkipped(array $skipped): self
	{
		$this->data['skipped'] = $skipped;

		return $this;
	}

	/**
	 * @return list<array{nodeName: string, title: string, references: list<string>}> the nodes whose
	 *         settings still point outside the fragment, named by the names they now carry
	 */
	public function getNeedsReview(): array
	{
		return $this->data['needsReview'] ?? [];
	}

	/**
	 * @param list<array{nodeName: string, title: string, references: list<string>}> $needsReview
	 */
	public function setNeedsReview(array $needsReview): self
	{
		$this->data['needsReview'] = $needsReview;

		return $this;
	}

	/**
	 * @return array the triple of the document type the fragment was cut from, for the text of the warning
	 */
	public function getSourceDocumentType(): array
	{
		return $this->data['sourceDocumentType'] ?? [];
	}

	public function setSourceDocumentType(array $documentType): self
	{
		$this->data['sourceDocumentType'] = $documentType;

		return $this;
	}
}
