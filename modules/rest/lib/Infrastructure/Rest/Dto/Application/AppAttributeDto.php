<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\Application;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Optional;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

class AppAttributeDto extends Dto
{
	#[Title("Application attribute code")]
	#[Filterable, Sortable]
	public string $code;

	#[Title("Application attribute value")]
	#[Editable]
	public mixed $value;
}
