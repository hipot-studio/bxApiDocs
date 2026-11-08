<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper;

use Bitrix\Tasks\Control\Log\TaskLogService;
use Bitrix\Tasks\V2\Internal\Entity\HistoryLog;
use Bitrix\Tasks\V2\Internal\Entity\HistoryLogCollection;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util\DateFormatter;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util\TextFormatter;

class TaskHistoryResponseMapper
{
	private const HISTORY_VALUE_MAX_LENGTH = 200;
	private const DATE_FIELD_TYPE = 'date';

	public function __construct(
		private readonly UserResponseMapper $userResponseMapper,
		private readonly TaskLogService $taskLogService,
	)
	{
	}

	/**
	 * @param string[] $userNames
	 */
	public function mapFromEntityCollection(HistoryLogCollection $logs, array $userNames, int $userId): array
	{
		return array_map(
			fn(HistoryLog $log): array => $this->mapFromEntity($log, $userId, $userNames[$log->userId] ?? null),
			$logs->getEntities(),
		);
	}

	public function mapFromEntity(HistoryLog $log, int $userId, ?string $userName = null): array
	{
		$user = $log->userId !== null ? $this->userResponseMapper->mapFromIdAndName($log->userId, $userName) : null;

		return [
			'field' => $log->field,
			'fromValue' => $this->formatValue($log->fromValue, $log->field, $userId),
			'toValue' => $this->formatValue($log->toValue, $log->field, $userId),
			'createdDate' => DateFormatter::formatTimestamp(
				$log->createdDateTs,
				DateFormatter::FORMAT_WITHOUT_TIMEZONE,
				$userId,
			),
			'author' => $user,
		];
	}

	private function formatValue(mixed $value, ?string $field, int $userId): ?string
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		if (is_numeric($value) && $this->isDateField($field))
		{
			return DateFormatter::formatTimestamp((int)$value, DateFormatter::FORMAT_WITHOUT_TIMEZONE, $userId);
		}

		return TextFormatter::truncate((string)$value, self::HISTORY_VALUE_MAX_LENGTH);
	}

	private function isDateField(?string $field): bool
	{
		if ($field === null)
		{
			return false;
		}

		$trackedFields = $this->taskLogService->getTrackedFields();
		$fieldType = $trackedFields[$field]['TYPE'] ?? null;

		return $fieldType === self::DATE_FIELD_TYPE;
	}
}
