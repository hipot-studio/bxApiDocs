<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class GetTaskForAnalysisDto
{
	use MapTypeTrait;

	public const MESSAGES_LIMIT_DEFAULT = 0;
	public const MESSAGES_LIMIT_MIN_VALIDATION = 0;
	public const MESSAGES_LIMIT_MIN = 1;
	public const MESSAGES_LIMIT_MAX = 50;

	public const HISTORY_LIMIT_DEFAULT = 0;
	public const HISTORY_LIMIT_MIN_VALIDATION = 0;
	public const HISTORY_LIMIT_MIN = 1;
	public const HISTORY_LIMIT_MAX = 50;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		#[Range(min: self::MESSAGES_LIMIT_MIN_VALIDATION, max: self::MESSAGES_LIMIT_MAX)]
		public readonly int $messagesLimit = self::MESSAGES_LIMIT_DEFAULT,
		#[Range(min: self::HISTORY_LIMIT_MIN_VALIDATION, max: self::HISTORY_LIMIT_MAX)]
		public readonly int $historyLimit = self::HISTORY_LIMIT_DEFAULT,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			taskId: static::mapInteger($props, 'taskId'),
			messagesLimit: static::mapInteger($props, 'messagesLimit') ?? self::MESSAGES_LIMIT_DEFAULT,
			historyLimit: static::mapInteger($props, 'historyLimit') ?? self::HISTORY_LIMIT_DEFAULT,
		);
	}
}
