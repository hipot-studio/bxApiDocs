<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Validation\Rule\NullablePositiveNumber;

class GetChatMessagesDto
{
	use MapTypeTrait;

	public const LIMIT_DEFAULT = 25;
	public const LIMIT_MIN = 1;
	public const LIMIT_MAX = 50;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		#[Range(min: self::LIMIT_MIN, max: self::LIMIT_MAX)]
		public readonly int $limit = self::LIMIT_DEFAULT,
		#[NullablePositiveNumber]
		public readonly ?int $lastId = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			taskId: static::mapInteger($props, 'taskId'),
			limit: static::mapInteger($props, 'limit') ?? self::LIMIT_DEFAULT,
			lastId: static::mapInteger($props, 'lastId'),
		);
	}
}
