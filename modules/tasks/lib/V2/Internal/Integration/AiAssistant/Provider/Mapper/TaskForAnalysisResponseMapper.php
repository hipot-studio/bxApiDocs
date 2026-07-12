<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper;

use Bitrix\Tasks\V2\Internal\Entity\Priority;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util\TextFormatter;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util\DateFormatter;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Enum\SignificantHistoryField;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Dto\TaskAnalyticsDto;

class TaskForAnalysisResponseMapper
{
	public const TITLE_MAX_LENGTH = 150;
	public const DESCRIPTION_MAX_LENGTH = 300;
	public const MESSAGE_TEXT_MAX_LENGTH = 200;
	public const HISTORY_VALUE_MAX_LENGTH = 200;

	private const NON_AI_READABLE_HISTORY_FIELDS = [
		SignificantHistoryField::Result->value,
		SignificantHistoryField::ResultEdit->value,
		SignificantHistoryField::Description->value,
	];

	public function __construct(
		private readonly TaskAnalyticsMapper $taskAnalyticsMapper,
		private readonly UserResponseMapper $userResponseMapper,
		private readonly LinkService $linkService,
	)
	{
	}

	public function map(
		Task $task,
		int $userId,
		TaskAnalyticsDto $analytics,
		?array $recentMessages = null,
		?array $recentHistory = null,
	): array
	{
		$title = TextFormatter::truncate($task->title, self::TITLE_MAX_LENGTH);

		$description = TextFormatter::stripTags($task->description);
		$description = TextFormatter::truncate($description, self::DESCRIPTION_MAX_LENGTH);

		return [
			'taskId' => $task->id,
			'title' => $title,
			'description' => $description,
			'status' => $task->status?->value,
			'deadline' => DateFormatter::formatTimestamp($task->deadlineTs),
			'createdDate' => DateFormatter::formatTimestamp($task->createdTs),
			'isImportant' => $task->priority === Priority::High,
			'stage' => $task->stage?->title,
			'link' => $this->linkService->get($task, $userId),
			'creator' => $this->userResponseMapper->mapFromEntity($task->creator),
			'responsible' => $this->userResponseMapper->mapFromEntity($task->responsible),
			'accomplices' => $this->userResponseMapper->mapFromEntityCollection($task->accomplices),
			'auditors' => $this->userResponseMapper->mapFromEntityCollection($task->auditors),
			'analytics' => $this->taskAnalyticsMapper->map($analytics),
			'recentMessages' => $this->mapMessages($recentMessages),
			'recentHistory' => $this->mapHistory($recentHistory),
		];
	}

	private function mapMessages(?array $messages): ?array
	{
		if ($messages === null)
		{
			return null;
		}

		$result = [];
		foreach ($messages as $message)
		{
			$result[] = $this->mapMessage($message);
		}

		return $result;
	}

	private function mapMessage(array $message): array
	{
		$result = [
			'createdDate' => null,
			'text' => null,
		];

		$createdDate = $message['CREATED_DATE'] ?? null;
		if ($createdDate !== null)
		{
			$result['createdDate'] = DateFormatter::formatDateTime($message['CREATED_DATE']);
		}

		$text = $message['TEXT'] ?? null;
		if ($text !== null)
		{
			$result['text'] = TextFormatter::stripTags($text);
			$result['text'] = TextFormatter::truncate($result['text'], self::MESSAGE_TEXT_MAX_LENGTH);
		}

		$result['author'] = $this->userResponseMapper->mapFromIdAndName(
			$message['USER_ID'] ?? null,
			$message['USER_NAME'] ?? null,
		);

		return $result;
	}

	private function mapHistory(?array $logs): ?array
	{
		if ($logs === null)
		{
			return null;
		}

		$result = [];
		foreach ($logs as $log)
		{
			$result[] = $this->mapLog($log);
		}

		return $result;
	}

	private function mapLog(array $log): array
	{
		$result = [
			'createdDate' => null,
			'field' => null,
			'fromValue' => null,
			'toValue' => null,
		];

		$createdDate = $log['CREATED_DATE'] ?? null;
		if ($createdDate !== null)
		{
			$result['createdDate'] = DateFormatter::formatDateTime($log['CREATED_DATE']);
		}

		$field = $log['FIELD'] ?? null;
		if ($field !== null)
		{
			$result['field'] = (string)$field;
		}

		$fromValue = $log['FROM_VALUE'] ?? null;
		if ($field !== null && $fromValue !== null && !in_array($field, self::NON_AI_READABLE_HISTORY_FIELDS, true))
		{
			$result['fromValue'] = TextFormatter::truncate($fromValue, self::HISTORY_VALUE_MAX_LENGTH);
		}

		$toValue = $log['TO_VALUE'] ?? null;
		if ($field !== null && $toValue !== null && !in_array($field, self::NON_AI_READABLE_HISTORY_FIELDS, true))
		{
			$result['toValue'] = TextFormatter::truncate($toValue, self::HISTORY_VALUE_MAX_LENGTH);
		}

		$result['author'] = $this->userResponseMapper->mapFromIdAndName(
			$log['USER_ID'] ?? null,
			$log['USER_NAME'] ?? null,
		);

		return $result;
	}
}
