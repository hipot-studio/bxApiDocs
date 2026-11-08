<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use JsonSerializable;

class LoadSettingsResponseDto implements JsonSerializable
{
	/**
	 *
	 * @param string $title
	 * @param string $description
	 * @param array<string, PortRuleDto> $portRuleDtoDictionary
	 * @param array<string, ActionDictionaryEntryDto> $actionEntryDtoDictionary
	 * @param array|null $fixedDocumentType Document type the node works on, as `[moduleId, entity, documentType]`;
	 *   null when the node has none and the document type of the edited template answers instead. A complex node
	 *   declares it on its class; a trigger publishes the document type of the event it reacts to (a trigger of
	 *   an event without a document publishes null). It is what the forms of its sub-actions are built for -
	 *   and not what the condition of the node compares fields of: that one is $conditionDocumentType, and a
	 *   node whose condition is decided on a document of its own publishes none of it. Not a filter gate:
	 *   `availableBlocks.filter.available` is the single answer about the filter surface, and for a trigger the
	 *   two are deliberately unrelated -
	 *   see {@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getPublishedDocumentTypeForNode()}.
	 * @param bool $filterSupported Kept for backward compatibility. Mirrors availableBlocks.filter.available;
	 *   only a node the capability catalog cannot describe at all (availableBlocks = null) falls back to the
	 *   runtime gate. The two can therefore never disagree.
	 * @param AvailableBlocksDto|null $availableBlocks Descriptor of available blocks.
	 * @param array<string, string> $autofillMap Relation autofill package for the "Create" sub-action:
	 *   target form field code => bizproc expression "{=<sourceBlockId>:<sourcePropertyId>}". Empty when
	 *   there are no relation sources or no unambiguous match.
	 * @param list<string> $autofillRequiredFields Target fields the relation package itself declares required.
	 *   A field the target document requires on its own is not among them: it is required regardless of any
	 *   relation, and the frontend acts on this list as on a conflict of the relation.
	 * @param list<array{fieldId: string, code: string, sourceBlockIds: list<string>}> $autofillConflicts
	 * @param ActionDictionaryEntryDto|null $relationAction
	 * @param array<string, PortRuleDto> $relationPortRuleDtoDictionary
	 * @param array|null $conditionDocumentType Document type the condition of the node is decided on, as
	 *   `[moduleId, entity, documentType]`; null when it is decided on the document of the edited template.
	 *   Answered by the channel of the condition document
	 *   ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getConditionDocumentTypeForNode()}),
	 *   the same resolver the reactive request of the panel is answered by, so a loaded value and a reactive
	 *   one are interchangeable. It decides nothing on the server: neither the composition of the blocks, nor
	 *   the catalog of the sub-actions, nor the authorization of the published type depend on it.
	 * @param array $conditionDocumentSourceFields `FieldName`s of the own settings of the node whose values
	 *   decide $conditionDocumentType, empty for a node whose condition document never moves. A non-empty list
	 *   is what tells the panel to re-ask the server while the form is being edited, and it is the key the
	 *   panel deduplicates those requests by.
	 * @param bool|null $baseSettingsHasFillableFields Whether the base settings of the node have any field for
	 *   a person to fill in: a node the unified panel serves may carry nothing but service properties, and both
	 *   forms of the panel then say so instead of showing an empty form. Null when the server has no answer to
	 *   give (no document type, an activity it cannot configure) - the client then asks the form itself, as it
	 *   did before this answer existed.
	 */
	public function __construct(
		public string $title,
		public string $description,
		public array $portRuleDtoDictionary,
		public array $actionEntryDtoDictionary,
		public ?array $fixedDocumentType = null,
		public bool $filterSupported = false,
		public ?AvailableBlocksDto $availableBlocks = null,
		public array $autofillMap = [],
		public ?ActionDictionaryEntryDto $relationAction = null,
		public array $relationPortRuleDtoDictionary = [],
		public ?array $conditionDocumentType = null,
		public array $conditionDocumentSourceFields = [],
		public array $autofillRequiredFields = [],
		public array $autofillConflicts = [],
		public ?bool $baseSettingsHasFillableFields = null,
	)
	{

	}

	public function jsonSerialize(): array
	{
		return [
			'title' => $this->title,
			'description' => $this->description,
			'rules' => $this->portRuleDtoDictionary,
			'actions' => $this->actionEntryDtoDictionary,
			'fixedDocumentType' => $this->fixedDocumentType,
			'fixedDocumentTypeSigned' => count($this->fixedDocumentType ?? []) === 3
				? \CBPDocument::signDocumentType($this->fixedDocumentType)
				: null,
			'filterSupported' => $this->filterSupported,
			'availableBlocks' => $this->availableBlocks,
			'autofillMap' => (object)$this->autofillMap,
			'autofillRequiredFields' => $this->autofillRequiredFields,
			'autofillConflicts' => $this->autofillConflicts,
			'relationAction' => $this->relationAction,
			'relations' => $this->relationPortRuleDtoDictionary,
			'conditionDocumentType' => $this->conditionDocumentType,
			'conditionDocumentSourceFields' => $this->conditionDocumentSourceFields,
			'baseSettingsHasFillableFields' => $this->baseSettingsHasFillableFields,
		];
	}
}
