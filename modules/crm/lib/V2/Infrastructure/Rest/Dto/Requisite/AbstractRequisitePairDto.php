<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Min;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * A requisite chosen in a CRM entity together with the bank requisite chosen beside it. The same shape
 * answers a read and carries the fields of a write.
 *
 * The pair is written as a whole: the bank requisite belongs to the requisite standing next to it, so
 * `requisiteId` is required on a write and `bankRequisiteId` alone is not a request the contract
 * accepts. Zero clears a choice, `null` says nothing is chosen.
 *
 * A non-empty identifier does not promise the record is still there - a requisite may have been
 * deleted after the choice was made, and a read answers with what was stored.
 *
 * Whose requisite is chosen is the descendant's to say, and so is the mapper of the route: each group
 * publishes its own `final` contract under its own name, carrying its own `#[MappedBy]`, because a
 * class attribute is not inherited and the name of a published DTO is part of the description of
 * REST v3.
 */
abstract class AbstractRequisitePairDto extends Dto
{
	#[Required(['set'])]
	#[Editable(['set'])]
	#[Min(0, groups: ['set'])]
	public ?int $requisiteId;

	#[Editable(['set'])]
	#[Min(0, groups: ['set'])]
	public ?int $bankRequisiteId;
}
