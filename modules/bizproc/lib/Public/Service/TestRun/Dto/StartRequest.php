<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\TestRun\Dto;

final class StartRequest
{
	/**
	 * @param array $values Values of the node outputs, keyed by the output name, in the format of
	 *   `BX.Bizproc.FieldType.renderControl` for the property of the output.
	 * @param string $documentSource One of `create`, `select`, `none`.
	 * @param array $documentFields Values of the fields of the document to create, for `create`.
	 * @param string|null $documentId Id of the existing document, for `select`.
	 */
	public function __construct(
		public readonly int $userId,
		public readonly int $templateId,
		public readonly string $blockId,
		public readonly int $draftId,
		public readonly int $draftRevision,
		public readonly array $values,
		public readonly string $documentSource,
		public readonly array $documentFields = [],
		public readonly ?string $documentId = null,
	)
	{
	}
}
