<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class CreateCheckListDto
{
	use MapTypeTrait;

	public function __construct(
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		#[NotEmpty]
		public readonly ?string $title = null,
		public readonly array $checkListItems = [],
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			taskId: static::mapInteger($props, 'taskId'),
			title: static::mapString($props, 'title'),
			checkListItems: static::mapArray($props, 'checkListItems') ?? [],
		);
	}
}
