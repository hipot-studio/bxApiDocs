<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Rest\V3\Attribute\Filterable;

final class BankRequisiteListFilterDto extends BankRequisiteDto
{
	#[Filterable]
	public int $requisiteId;

	#[Filterable]
	public int $contactId;

	#[Filterable]
	public int $companyId;
}
