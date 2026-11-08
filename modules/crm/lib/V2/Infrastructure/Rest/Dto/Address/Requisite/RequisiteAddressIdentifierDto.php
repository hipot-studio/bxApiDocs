<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Requisite;

use Bitrix\Rest\V3\Dto\Dto;

final class RequisiteAddressIdentifierDto extends Dto
{
	public int $requisiteId;

	public int $typeId;
}
