<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class IntegrationsDto extends Dto
{
	public bool $whatsAppEnabled;

	/** @var DtoCollection<AdIntegrationDto> */
	#[ElementType(AdIntegrationDto::class)]
	public DtoCollection $ads;
}
