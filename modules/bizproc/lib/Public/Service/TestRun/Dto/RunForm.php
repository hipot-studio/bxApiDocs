<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\TestRun\Dto;

final class RunForm
{
	/**
	 * @param FieldDescription[] $returnFields Outputs of the trigger node, without the document ones.
	 * @param string $documentSource Source offered by default: one of `create`, `select`, `none`.
	 * @param FieldDescription[] $createFields Fields of the form creating the document, for `create`.
	 * @param string|null $selectorEntityId Entity of `ui.entity-selector`, for `select`.
	 */
	public function __construct(
		public readonly int $draftId,
		public readonly int $draftRevision,
		public readonly string $blockId,
		public readonly string $triggerTitle,
		public readonly array $returnFields,
		public readonly string $documentSource,
		public readonly bool $canCreateDocument,
		public readonly bool $canSelectDocument,
		public readonly array $createFields = [],
		public readonly ?string $selectorEntityId = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'draftId' => $this->draftId,
			'draftRevision' => $this->draftRevision,
			'trigger' => [
				'blockId' => $this->blockId,
				'title' => $this->triggerTitle,
			],
			'returnFields' => array_values(array_map(
				static fn(FieldDescription $field): array => $field->toArray(),
				$this->returnFields,
			)),
			'document' => [
				'source' => $this->documentSource,
				'canCreate' => $this->canCreateDocument,
				'canSelect' => $this->canSelectDocument,
				'createFields' => array_values(array_map(
					static fn(FieldDescription $field): array => $field->toArray(),
					$this->createFields,
				)),
				'selector' => $this->selectorEntityId === null ? null : ['entityId' => $this->selectorEntityId],
			],
		];
	}
}
