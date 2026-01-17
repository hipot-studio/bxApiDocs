<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class AddResultDto
{
	use MapTypeTrait;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		#[NotEmpty]
		public readonly ?string $text = null,
		#[PositiveNumber]
		public readonly ?int $authorId = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			taskId: static::mapInteger($props, 'taskId'),
			text: static::mapString($props, 'text'),
			authorId: static::mapInteger($props, 'authorId'),
		);
	}
}
