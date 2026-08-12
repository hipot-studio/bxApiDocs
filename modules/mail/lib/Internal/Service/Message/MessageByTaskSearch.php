<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Integration\Tasks\TaskFinder;
use Bitrix\Mail\Internal\Entity\Message\SearchMessagesByTasksRequest;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Public\Service\Access\MessageAccessService;

class MessageByTaskSearch
{
	private const DATE_FORMAT = 'Y/m/d H:i';
	private const MIN_TASK_BATCH_LIMIT = 100;
	private const TASK_BATCH_LIMIT_MULTIPLIER = 4;

	public function __construct(
		private readonly TaskFinder $taskFinder,
		private readonly MessageAccessService $accessService,
	)
	{
	}

	public function search(SearchMessagesByTasksRequest $request, int $userId): array
	{
		$pagination = $this->collectTasksByEmailPage($request, $userId);
		$tasksByEmailId = $pagination['tasksByEmailId'];
		if (empty($tasksByEmailId))
		{
			return $this->createResponse([], false, null, $request);
		}

		$messageRows = $this->loadMessages(array_keys($tasksByEmailId));
		$accessByMessageTask = $this->loadAccessByMessageTask($tasksByEmailId);

		$messageRows = $this->filterAccessibleRows($messageRows, $tasksByEmailId, $accessByMessageTask, $userId);
		$messageUrls = $this->buildMessageUrls($messageRows, $tasksByEmailId, $accessByMessageTask, $userId);

		return $this->createResponse(
			$this->formatMessages($messageRows, $tasksByEmailId, $messageUrls),
			$pagination['hasMore'],
			$pagination['nextOffset'],
			$request,
		);
	}

	private function mapState(?string $state): ?string
	{
		return match ($state)
		{
			SearchMessagesByTasksRequest::TASK_STATE_OPEN => TaskFinder::STATE_OPEN,
			SearchMessagesByTasksRequest::TASK_STATE_CLOSED => TaskFinder::STATE_CLOSED,
			default => null,
		};
	}

	private function createResponse(array $messages, bool $hasMore, ?int $nextOffset, SearchMessagesByTasksRequest $request): array
	{
		return [
			'messages' => $messages,
			'hasMore' => $hasMore,
			'nextOffset' => $nextOffset,
			'limit' => $request->limit,
			'offset' => $request->offset,
		];
	}

	/**
	 * Collects a page by accessible unique email messages, not by linked task rows.
	 *
	 * @return array{
	 *   tasksByEmailId: array<int, array<int, array>>,
	 *   hasMore: bool,
	 *   nextOffset: int|null
	 * }
	 */
	private function collectTasksByEmailPage(SearchMessagesByTasksRequest $request, int $userId): array
	{
		$limit = max(1, $request->limit);
		$offset = max(0, $request->offset);
		$requiredAccessibleCount = $offset + $limit + 1;
		$taskBatchLimit = $this->resolveTaskBatchLimit($limit);
		$taskOffset = 0;
		$tasksByEmailId = [];
		$accessibleMessageIds = [];
		$accessibilityByMessageId = [];

		do
		{
			$tasks = $this->findTasksBatch($request, $userId, $taskBatchLimit, $taskOffset);
			$taskOffset += count($tasks);
			$hasMoreTasks = count($tasks) === $taskBatchLimit;

			$changedMessageIds = $this->appendTasksByEmailId($tasksByEmailId, $tasks);
			if (!empty($changedMessageIds))
			{
				$changedTasksByEmailId = $this->filterTasksByMessageIds($tasksByEmailId, $changedMessageIds);
				$changedAccessibleMessageIds = $this->getAccessibleMessageIds($changedTasksByEmailId, $userId);
				$changedAccessibleMap = array_fill_keys($changedAccessibleMessageIds, true);

				foreach ($changedMessageIds as $messageId)
				{
					$accessibilityByMessageId[$messageId] = isset($changedAccessibleMap[$messageId]);
				}

				$accessibleMessageIds = $this->filterAccessibleMessageIds(
					array_keys($tasksByEmailId),
					$accessibilityByMessageId,
				);
			}
		}
		while ($hasMoreTasks && count($accessibleMessageIds) < $requiredAccessibleCount);

		$pageMessageIds = array_slice($accessibleMessageIds, $offset, $limit);
		$hasMore = count($accessibleMessageIds) > $offset + count($pageMessageIds);

		return [
			'tasksByEmailId' => $this->filterTasksByMessageIds($tasksByEmailId, $pageMessageIds),
			'hasMore' => $hasMore,
			'nextOffset' => $hasMore ? $offset + count($pageMessageIds) : null,
		];
	}

	private function resolveTaskBatchLimit(int $messageLimit): int
	{
		return max(self::MIN_TASK_BATCH_LIMIT, $messageLimit * self::TASK_BATCH_LIMIT_MULTIPLIER);
	}

	private function findTasksBatch(
		SearchMessagesByTasksRequest $request,
		int $userId,
		int $limit,
		int $offset,
	): array
	{
		return $this->taskFinder->findTasksLinkedToMail(
			userId: $userId,
			state: $this->mapState($request->taskState),
			overdued: $request->taskOverdued,
			createdFrom: $request->taskCreatedFrom,
			createdTo: $request->taskCreatedTo,
			responsibleId: $request->taskResponsibleId,
			limit: $limit,
			offset: $offset,
		);
	}

	/**
	 * @param array<int, array<int, array>> $tasksByEmailId
	 * @return int[]
	 */
	private function appendTasksByEmailId(array &$tasksByEmailId, array $tasks): array
	{
		$changedMessageIds = [];

		foreach ($tasks as $task)
		{
			$emailId = (int)($task['emailId'] ?? 0);
			if ($emailId <= 0)
			{
				continue;
			}

			$tasksByEmailId[$emailId][] = $task;
			$changedMessageIds[$emailId] = true;
		}

		return array_keys($changedMessageIds);
	}

	/**
	 * @param array<int, array<int, array>> $tasksByEmailId
	 * @return int[]
	 */
	private function getAccessibleMessageIds(array $tasksByEmailId, int $userId): array
	{
		if (empty($tasksByEmailId))
		{
			return [];
		}

		$messageRows = $this->loadMessages(array_keys($tasksByEmailId));
		$accessByMessageTask = $this->loadAccessByMessageTask($tasksByEmailId);
		$accessibleRows = $this->filterAccessibleRows($messageRows, $tasksByEmailId, $accessByMessageTask, $userId);

		$accessibleMap = [];
		foreach ($accessibleRows as $row)
		{
			$messageId = (int)($row['MESSAGE_ID'] ?? 0);
			if ($messageId > 0)
			{
				$accessibleMap[$messageId] = true;
			}
		}

		return array_values(array_filter(
			array_keys($tasksByEmailId),
			static fn (int $messageId): bool => isset($accessibleMap[$messageId]),
		));
	}

	/**
	 * @param array<int, array<int, array>> $tasksByEmailId
	 * @param int[] $messageIds
	 * @return array<int, array<int, array>>
	 */
	private function filterTasksByMessageIds(array $tasksByEmailId, array $messageIds): array
	{
		$result = [];
		foreach ($messageIds as $messageId)
		{
			if (isset($tasksByEmailId[$messageId]))
			{
				$result[$messageId] = $tasksByEmailId[$messageId];
			}
		}

		return $result;
	}

	/**
	 * @param int[] $messageIds
	 * @param array<int, bool> $accessibilityByMessageId
	 * @return int[]
	 */
	private function filterAccessibleMessageIds(array $messageIds, array $accessibilityByMessageId): array
	{
		return array_values(array_filter(
			$messageIds,
			static fn (int $messageId): bool => ($accessibilityByMessageId[$messageId] ?? false) === true,
		));
	}

	protected function loadMessages(array $messageIds): array
	{
		if (empty($messageIds))
		{
			return [];
		}

		$query = QueryBuilder::buildDefaultMessagesDetailsQuery($messageIds, []);

		return $query->fetchAll();
	}

	protected function loadAccessByMessageTask(array $tasksByEmailId): array
	{
		$messageIds = array_keys($tasksByEmailId);
		$taskIds = array_values(array_unique(array_map(
			static fn (array $task): int => (int)$task['id'],
			array_merge(...array_values($tasksByEmailId)),
		)));

		if (empty($messageIds) || empty($taskIds))
		{
			return [];
		}

		$rows = MessageAccessTable::query()
			->setSelect(['MESSAGE_ID', 'ENTITY_ID', 'TOKEN', 'SECRET', 'ENTITY_TYPE', 'MAILBOX_ID'])
			->whereIn('MESSAGE_ID', $messageIds)
			->whereIn('ENTITY_ID', $taskIds)
			->where('ENTITY_TYPE', MessageAccessTable::ENTITY_TYPE_TASKS_TASK)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['MESSAGE_ID']][(int)$row['ENTITY_ID']] = $row;
		}

		return $result;
	}

	protected function filterAccessibleRows(array $rows, array $tasksByEmailId, array $accessByMessageTask, int $userId): array
	{
		$mailboxAccess = [];
		$isMailboxAccessible = static function (int $mailboxId) use (&$mailboxAccess, $userId): bool {
			if ($mailboxId <= 0)
			{
				return false;
			}
			if (!array_key_exists($mailboxId, $mailboxAccess))
			{
				$mailboxAccess[$mailboxId] = (bool)MailboxTable::getUserMailbox($mailboxId, $userId);
			}

			return $mailboxAccess[$mailboxId];
		};

		return array_values(array_filter($rows, static function (array $row) use ($tasksByEmailId, $accessByMessageTask, $isMailboxAccessible): bool {
			$messageId = (int)$row['MESSAGE_ID'];
			if ($messageId <= 0)
			{
				return false;
			}

			if ($isMailboxAccessible((int)($row['MAILBOX_ID'] ?? 0)))
			{
				return true;
			}

			foreach ($tasksByEmailId[$messageId] ?? [] as $task)
			{
				if (isset($accessByMessageTask[$messageId][(int)$task['id']]))
				{
					return true;
				}
			}

			return false;
		}));
	}

	protected function buildMessageUrls(array $rows, array $tasksByEmailId, array $accessByMessageTask, int $userId): array
	{
		$urls = [];

		foreach ($rows as $row)
		{
			$messageId = (int)$row['MESSAGE_ID'];
			if ($messageId <= 0)
			{
				continue;
			}

			$baseUrl = Message::getMessageUrl($messageId);
			$urls[$messageId] = $baseUrl;

			foreach ($tasksByEmailId[$messageId] ?? [] as $task)
			{
				$access = $accessByMessageTask[$messageId][(int)$task['id']] ?? null;
				if ($access !== null)
				{
					$urls[$messageId] = $this->accessService->getLinkWithToken($baseUrl, $access, $userId);
					break;
				}
			}
		}

		return $urls;
	}

	/**
	 * @param array<int, array<int, array>> $tasksByEmailId emailId => list of tasks
	 * @param array<int, string> $messageUrls messageId => url
	 */
	private function formatMessages(array $rows, array $tasksByEmailId, array $messageUrls): array
	{
		$messages = [];

		foreach ($rows as $row)
		{
			$messageId = (int)$row['MESSAGE_ID'];
			if ($messageId <= 0 || isset($messages[$messageId]))
			{
				continue;
			}

			$messages[$messageId] = [
				'id' => $messageId,
				'mailboxId' => (int)($row['MAILBOX_ID'] ?? 0),
				'mailboxEmail' => (string)($row['MAILBOX_EMAIL'] ?? ''),
				'subject' => (string)($row['SUBJECT'] ?? ''),
				'from' => (string)($row['FIELD_FROM'] ?? ''),
				'to' => (string)($row['FIELD_TO'] ?? ''),
				'date' => $row['FIELD_DATE'] instanceof DateTime
					? $row['FIELD_DATE']->format(self::DATE_FORMAT)
					: (string)($row['FIELD_DATE'] ?? ''),
				'isSeen' => in_array($row['IS_SEEN'] ?? '', ['Y', 'S'], true),
				'hasAttachments' => !empty($row['ATTACHMENTS']),
				'url' => $messageUrls[$messageId] ?? Message::getMessageUrl($messageId),
				'tasks' => $this->formatTasks($tasksByEmailId[$messageId] ?? []),
			];
		}

		return array_values($messages);
	}

	private function formatTasks(array $tasks): array
	{
		$result = [];
		foreach ($tasks as $task)
		{
			$result[] = [
				'id' => $task['id'],
				'title' => $task['title'],
				'status' => $task['status'],
				'deadline' => $task['deadlineTs'] !== null
					? DateTime::createFromTimestamp($task['deadlineTs'])->format(self::DATE_FORMAT)
					: null,
				'responsibleId' => $task['responsibleId'],
			];
		}

		return $result;
	}
}
