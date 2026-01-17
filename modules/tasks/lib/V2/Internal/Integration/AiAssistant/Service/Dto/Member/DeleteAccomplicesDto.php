<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Member;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class DeleteAccomplicesDto
{
	use MapTypeTrait;

	public function __construct(
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		#[NotEmpty]
		public readonly ?array $accompliceIds = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			taskId: static::mapInteger($props, 'taskId'),
			accompliceIds: static::mapArray($props, 'accompliceIds', 'intval'),
		);
	}
}
