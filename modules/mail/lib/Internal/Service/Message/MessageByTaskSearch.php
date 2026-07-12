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

	public function __construct(
		private readonly TaskFinder $taskFinder,
		private readonly MessageAccessService $accessService,
	)
	{
	}

	public function search(SearchMessagesByTasksRequest $request, int $userId): array
	{
		$tasks = $this->taskFinder->findTasksLinkedToMail(
			userId: $userId,
			state: $this->mapState($request->taskState),
			overdued: $request->taskOverdued,
			createdFrom: $request->taskCreatedFrom,
			createdTo: $request->taskCreatedTo,
			responsibleId: $request->taskResponsibleId,
			limit: $request->limit,
		);

		$tasksByEmailId = $this->indexTasksByEmailId($tasks, $request->limit);
		if (empty($tasksByEmailId))
		{
			return ['messages' => []];
		}

		$messageRows = $this->loadMessages(array_keys($tasksByEmailId));
		$accessByMessageTask = $this->loadAccessByMessageTask($tasksByEmailId);

		$messageRows = $this->filterAccessibleRows($messageRows, $tasksByEmailId, $accessByMessageTask, $userId);
		$messageUrls = $this->buildMessageUrls($messageRows, $tasksByEmailId, $accessByMessageTask, $userId);

		return ['messages' => $this->formatMessages($messageRows, $tasksByEmailId, $messageUrls)];
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

	/**
	 * Groups tasks by their linked emailId, capped at $limit unique emails.
	 *
	 * @return array<int, array<int, array>> emailId => list of tasks
	 */
	private function indexTasksByEmailId(array $tasks, int $limit): array
	{
		$index = [];
		foreach ($tasks as $task)
		{
			$emailId = $task['emailId'];
			if (!isset($index[$emailId]) && count($index) >= $limit)
			{
				continue;
			}
			$index[$emailId][] = $task;
		}

		return $index;
	}

	private function loadMessages(array $messageIds): array
	{
		if (empty($messageIds))
		{
			return [];
		}

		$query = QueryBuilder::buildDefaultMessagesDetailsQuery($messageIds, []);

		return $query->fetchAll();
	}

	private function loadAccessByMessageTask(array $tasksByEmailId): array
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

	private function filterAccessibleRows(array $rows, array $tasksByEmailId, array $accessByMessageTask, int $userId): array
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

	private function buildMessageUrls(array $rows, array $tasksByEmailId, array $accessByMessageTask, int $userId): array
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
