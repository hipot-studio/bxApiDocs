<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class DependencyGroupDto extends Dto
{
	public string $logic;

	/** @var DtoCollection<DependencyRuleDto> */
	#[ElementType(DependencyRuleDto::class)]
	public DtoCollection $rules;
}
