<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class LayoutFieldsDto extends Dto
{
	#[Editable]
	#[Required(['add'])]
	#[ElementType(ConfigurationSectionDto::class)]
	public DtoCollection $configuration;

	#[Editable]
	#[Required(['add'])]
	#[NotEmpty(allowZero: true, allowSpaces: true)]
	public string $name;

	#[Editable]
	#[Required(['add'])]
	#[ElementType(AudienceItemDto::class)]
	public DtoCollection $audience;
}
