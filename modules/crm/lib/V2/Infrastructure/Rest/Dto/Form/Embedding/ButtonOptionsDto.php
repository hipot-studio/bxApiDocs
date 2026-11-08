<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The values the button settings accept. The parameters the form dictionary knows nothing about -
 * the mode flags, the texts and the colours - have no enumeration and are absent here.
 */
final class ButtonOptionsDto extends Dto
{
	#[ElementsType(Type::String)]
	#[ElementType('string')]
	public array $font;

	#[ElementsType(Type::String)]
	#[ElementType('string')]
	public array $align;
}
