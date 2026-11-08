<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class GetTaskHistoryDto
{
	use MapTypeTrait;

	public const LIMIT_DEFAULT = 25;
	public const LIMIT_MIN = 1;
	public const LIMIT_MAX = 50;
	public const OFFSET_MIN = 0;
	public const OFFSET_MAX = 500;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		#[Range(min: self::LIMIT_MIN, max: self::LIMIT_MAX)]
		public readonly int $limit = self::LIMIT_DEFAULT,
		#[Range(min: self::OFFSET_MIN, max: self::OFFSET_MAX)]
		public readonly int $offset = 0,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			taskId: static::mapInteger($props, 'taskId'),
			limit: static::mapInteger($props, 'limit') ?? self::LIMIT_DEFAULT,
			offset: static::mapInteger($props, 'offset') ?? 0,
		);
	}
}
