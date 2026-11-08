<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class AdIntegrationDto extends Dto
{
	public int $direction;

	public string $providerCode;

	public string $accountId;

	public ?string $accountName;

	public string $formId;

	public ?string $formName;

	/** @var DtoCollection<AdFieldMappingDto> */
	#[ElementType(AdFieldMappingDto::class)]
	public DtoCollection $fieldMappings;
}
