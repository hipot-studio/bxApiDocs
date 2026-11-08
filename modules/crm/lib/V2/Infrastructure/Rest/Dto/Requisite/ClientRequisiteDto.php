<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\ClientRequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Min;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The requisite a CRM entity has chosen among those of its client, together with the bank requisite
 * chosen beside it. The same shape answers a read and carries the fields of a write.
 *
 * The pair is written as a whole: the bank requisite belongs to the requisite standing next to it, so
 * `requisiteId` is required on a write and `bankRequisiteId` alone is not a request the contract
 * accepts. Zero clears a choice, `null` says nothing is chosen.
 *
 * A non-empty identifier does not promise the record is still there - a requisite may have been
 * deleted after the choice was made, and a read answers with what was stored.
 */
#[MappedBy(ClientRequisiteDtoMapper::class)]
final class ClientRequisiteDto extends Dto
{
	#[Required(['set'])]
	#[Editable(['set'])]
	#[Min(0, groups: ['set'])]
	public ?int $requisiteId;

	#[Editable(['set'])]
	#[Min(0, groups: ['set'])]
	public ?int $bankRequisiteId;
}
