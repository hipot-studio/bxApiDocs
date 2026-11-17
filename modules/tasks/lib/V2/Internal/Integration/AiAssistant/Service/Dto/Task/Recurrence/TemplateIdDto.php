<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;

abstract class TemplateIdDto
{
	use MapTypeTrait;

	protected function __construct(
		public readonly ?int $templateId = null,
		public readonly ?int $taskId = null,
	)
	{
		static::validateIdentifier($templateId, $taskId);
	}

	public function usesTaskId(): bool
	{
		return $this->taskId !== null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function getIdentifierProperties(): array
	{
		return [
			'templateId' => [
				'type' => 'integer',
				'description' =>
					'ID of one recurring task template. Use this field instead of taskId.'
				,
				'minimum' => 1,
			],
			'taskId' => [
				'type' => 'integer',
				'description' =>
					'ID of an original recurring task or one of its generated instances. '
					. 'The linked recurring task template is resolved automatically. Use this field instead of templateId.'
				,
				'minimum' => 1,
			],
		];
	}

	public static function getIdentifierOneOf(): array
	{
		return [
			['required' => ['templateId']],
			['required' => ['taskId']],
		];
	}

	protected static function validateIdentifier(?int $templateId, ?int $taskId): void
	{
		if (($templateId === null) === ($taskId === null))
		{
			throw new DtoValidationException('Provide exactly one of templateId or taskId.');
		}

		$identifier = $templateId ?? $taskId;
		if ($identifier <= 0)
		{
			$fieldName = $templateId !== null ? 'templateId' : 'taskId';

			throw new DtoValidationException("{$fieldName} must be a positive integer.");
		}
	}
}
