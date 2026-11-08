<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto;

use Bitrix\Main\Infrastructure\Rest\Dto\Mapping\UserFieldTypeMapper;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(UserFieldTypeMapper::class)]
class UserFieldTypeDto extends Dto
{
	public ?string $userTypeId;

	public ?string $description;
}
