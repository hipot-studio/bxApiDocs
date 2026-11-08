<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout;

use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

final class AudienceItemDto extends Dto
{
	#[Editable]
	#[Required]
	#[InArray(['user', 'department', 'project', 'structure-node'], strict: true, showValues: true)]
	public string $type;

	#[Editable]
	#[Required]
	#[PositiveNumber]
	public int $id;
}
