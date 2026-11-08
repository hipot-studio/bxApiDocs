<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class AgreementsDto extends Dto
{
	public bool $enabled;

	/** @var DtoCollection<AgreementItemDto> */
	#[ElementType(AgreementItemDto::class)]
	public DtoCollection $items;
}
