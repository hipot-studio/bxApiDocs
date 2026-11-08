<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\TestRun\Dto;

/**
 * Summary of the test run the user has open on a template. The link to the document is built by the
 * controller of the designer, so it is none of the service.
 */
final class ActiveRun
{
	/**
	 * @param array $documentId `[moduleId, entity, documentId]`
	 * @param float $startTime Unix time of the start, in seconds
	 */
	public function __construct(
		public readonly int $sessionId,
		public readonly ?string $workflowId,
		public readonly array $documentId,
		public readonly string $blockId,
		public readonly int $draftId,
		public readonly float $startTime,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'sessionId' => $this->sessionId,
			'workflowId' => $this->workflowId,
			'documentId' => $this->documentId,
			'blockId' => $this->blockId,
			'draftId' => $this->draftId,
			'startTime' => $this->startTime,
		];
	}
}
