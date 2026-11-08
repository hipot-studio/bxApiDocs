<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Configuration;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class SmartProcessDto extends Dto
{
	public int $entityTypeId;

	public string $entityName;

	public string $name;

	/** @var DtoCollection<NamedIdDto> */
	#[ElementType(NamedIdDto::class)]
	public DtoCollection $categories;
}
