<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use JsonSerializable;

/**
 * Transport DTO for the getConditionDocumentType endpoint.
 *
 * A success is always this object, and `conditionDocumentType: null` inside it is a legal answer - "the
 * condition of this node is decided on the document of the edited template". A bare null is returned only
 * together with a collection of errors: the transport of the panel collapses every non-success to null, and
 * the consumer would otherwise be unable to tell a removed document from a refusal.
 *
 * The field is named the way the same value is named in the answer to opening a node
 * ({@see LoadSettingsResponseDto}): a loaded value and a reactive one come from one resolver, so the client
 * has no reason to tell them apart.
 */
class ConditionDocumentTypeResponseDto implements JsonSerializable
{
	/**
	 * @param array|null $conditionDocumentType Complex document type `[moduleId, entity, documentType]`, or
	 *   null while the condition is decided on the document of the edited template.
	 */
	public function __construct(
		public readonly ?array $conditionDocumentType = null,
	) {}

	public function jsonSerialize(): array
	{
		return [
			'conditionDocumentType' => $this->conditionDocumentType,
		];
	}
}
