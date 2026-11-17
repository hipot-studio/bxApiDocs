<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence;

use Bitrix\Tasks\Replication\Template\Enum\DeadlineType;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Validation\Rule\NullableMin;

class SetRecurringInstanceDeadlineDto extends TemplateIdDto
{
	private function __construct(
		?int $templateId = null,
		?int $taskId = null,
		#[NullableMin(0)]
		public readonly ?int $deadlineAfter = null,
		public readonly ?bool $matchesWorkTime = null,
		public readonly ?DeadlineType $deadlineType = null,
	)
	{
		parent::__construct($templateId, $taskId);
	}

	public static function fromArray(array $props): self
	{
		$templateId = static::mapInteger($props, 'templateId');
		$taskId = static::mapInteger($props, 'taskId');
		static::validateIdentifier($templateId, $taskId);

		$deadlineAfter = static::mapInteger($props, 'deadlineAfter');
		$deadlineType = static::mapBackedEnum($props, 'deadlineType', DeadlineType::class);
		if (isset($props['deadlineType']) && $deadlineType === null)
		{
			throw new DtoValidationException(
				'deadlineType must be ' . DeadlineType::CreationDay->value . '.',
			);
		}

		if ($deadlineAfter === null && $deadlineType === DeadlineType::CreationDay)
		{
			$deadlineAfter = 0;
		}

		if ($deadlineAfter === null)
		{
			throw new DtoValidationException(
				'Provide deadlineAfter, deadlineType, or both. deadlineAfter must be a non-negative integer.',
			);
		}

		return new self(
			templateId: $templateId,
			taskId: $taskId,
			deadlineAfter: $deadlineAfter,
			matchesWorkTime: static::mapBool($props, 'matchesWorkTime'),
			deadlineType: $deadlineType,
		);
	}

	public function setsCreationDay(): bool
	{
		return $this->deadlineType === DeadlineType::CreationDay && $this->deadlineAfter === 0;
	}
}
