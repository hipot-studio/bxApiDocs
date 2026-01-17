<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class SendChatMessageDto
{
	use MapTypeTrait;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		#[NotEmpty]
		public readonly ?string $text = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new static(
			taskId: static::mapInteger($props, 'taskId'),
			text: static::mapString($props, 'text'),
		);
	}
}
