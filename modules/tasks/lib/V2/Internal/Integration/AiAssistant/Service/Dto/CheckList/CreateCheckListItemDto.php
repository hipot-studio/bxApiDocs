<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class CreateCheckListItemDto
{
	use MapTypeTrait;

	public function __construct(
		#[NotEmpty]
		public readonly ?string $title = null,
		#[PositiveNumber]
		public readonly ?int $checkListId = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			title: static::mapString($props, 'title'),
			checkListId: static::mapInteger($props, 'checkListId'),
		);
	}
}
