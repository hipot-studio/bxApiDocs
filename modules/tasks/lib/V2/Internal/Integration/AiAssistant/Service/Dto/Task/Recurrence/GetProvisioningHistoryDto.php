<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence;

use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\GetTaskHistoryDto;

class GetProvisioningHistoryDto extends TemplateIdDto
{
	public const LIMIT_DEFAULT = GetTaskHistoryDto::LIMIT_DEFAULT;
	public const LIMIT_MIN = GetTaskHistoryDto::LIMIT_MIN;
	public const LIMIT_MAX = GetTaskHistoryDto::LIMIT_MAX;
	public const OFFSET_MIN = GetTaskHistoryDto::OFFSET_MIN;
	public const OFFSET_MAX = GetTaskHistoryDto::OFFSET_MAX;

	private function __construct(
		?int $templateId = null,
		?int $taskId = null,
		#[Range(min: self::LIMIT_MIN, max: self::LIMIT_MAX)]
		public readonly int $limit = self::LIMIT_DEFAULT,
		#[Range(min: self::OFFSET_MIN, max: self::OFFSET_MAX)]
		public readonly int $offset = 0,
	)
	{
		parent::__construct($templateId, $taskId);
	}

	public static function fromArray(array $props): self
	{
		return new self(
			templateId: static::mapInteger($props, 'templateId'),
			taskId: static::mapInteger($props, 'taskId'),
			limit: static::mapInteger($props, 'limit') ?? self::LIMIT_DEFAULT,
			offset: static::mapInteger($props, 'offset') ?? 0,
		);
	}
}
