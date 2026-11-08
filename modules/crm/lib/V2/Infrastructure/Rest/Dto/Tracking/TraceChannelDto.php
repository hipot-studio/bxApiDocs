<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * A channel the visitor of a trace came through: its code and its value, such as a form id or a phone.
 *
 * The code is a string rather than an enum of the transport: the closed list of codes belongs to the
 * domain, and an unknown code is answered as an error of this field by the mapping.
 */
final class TraceChannelDto extends Dto
{
	#[Editable(['add'])]
	#[Required(['add'])]
	public string $code;

	#[Editable(['add'])]
	public ?string $value;
}
