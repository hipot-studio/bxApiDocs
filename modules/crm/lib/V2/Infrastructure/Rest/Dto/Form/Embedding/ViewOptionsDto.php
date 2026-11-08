<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The values every display setting of the variant accepts. Each list is named after the setting it
 * belongs to, and a setting the variant does not have is absent here as well.
 */
final class ViewOptionsDto extends Dto
{
	#[ElementsType(Type::String)]
	#[ElementType('string')]
	public array $type;

	#[ElementsType(Type::String)]
	#[ElementType('string')]
	public array $position;

	#[ElementsType(Type::String)]
	#[ElementType('string')]
	public array $vertical;

	#[ElementsType(Type::Integer)]
	#[ElementType('int')]
	public array $delay;

	public ButtonOptionsDto $button;
}
