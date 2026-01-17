<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class GetTaskByIdDto
{
	use MapTypeTrait;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $taskId = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			taskId: static::mapInteger($props, 'taskId'),
		);
	}
}
