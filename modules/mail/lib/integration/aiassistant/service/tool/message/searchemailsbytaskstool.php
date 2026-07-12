<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Internal\Entity\Message\SearchMessagesByTasksRequest;
use Bitrix\Mail\Internal\Service\Message\MessageByTaskSearch;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;

class SearchEmailsByTasksTool extends ToolContract
{
	public const ACTION_NAME = 'search_emails_by_tasks';

	private const DEFAULT_LIMIT = 25;
	private const MAX_LIMIT = 100;

	public function __construct(
		private readonly MessageByTaskSearch $messageByTaskSearch,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return self::ACTION_NAME;
	}

	public function getDescription(): string
	{
		return
			"Searches email messages that have linked tasks matching the given criteria. "
			. "Returns an array of messages with id, subject, from, to, date, isSeen, "
			. "hasAttachments, url, and a 'tasks' array describing the linked tasks "
			. "(id, title, status, deadline, responsibleId). "
			. "Use this for queries like 'emails with open tasks', 'emails with overdue tasks', "
			. "or 'emails whose tasks were created in a period'. "
			. "All filters are optional — when omitted, the only constraint is 'email has at least "
			. "one linked task'. Limit defaults to " . self::DEFAULT_LIMIT . ", max " . self::MAX_LIMIT . ". "
			. "Searches across all mailboxes the user has access to via their tasks."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'taskState' => [
					'type' => 'string',
					'enum' => [
						SearchMessagesByTasksRequest::TASK_STATE_OPEN,
						SearchMessagesByTasksRequest::TASK_STATE_CLOSED,
					],
					'description' =>
						"'open' — task is new/pending/in progress; "
						. "'closed' — task is completed/deferred/declined. Omit for any state.",
				],
				'taskOverdued' => [
					'type' => 'boolean',
					'description' =>
						'When true, filter by deadline lateness. '
						. "By default (or with taskState='open') means currently overdue — deadline has passed and the task is still open. "
						. "Combined with taskState='closed' means closed past deadline (task was completed late).",
				],
				'taskCreatedFrom' => [
					'type' => 'string',
					'format' => 'date-time',
					'description' => "Lower bound of the task creation date in 'Y/m/d H:i' format.",
				],
				'taskCreatedTo' => [
					'type' => 'string',
					'format' => 'date-time',
					'description' => "Upper bound of the task creation date in 'Y/m/d H:i' format.",
				],
				'taskResponsibleId' => [
					'type' => 'integer',
					'description' => 'Filter by the task responsible user id.',
					'minimum' => 1,
				],
				'limit' => [
					'type' => 'integer',
					'description' => 'Maximum number of messages to return. Defaults to ' . self::DEFAULT_LIMIT . ', max ' . self::MAX_LIMIT . '.',
					'minimum' => 1,
					'maximum' => self::MAX_LIMIT,
				],
			],
			'required' => [],
			'additionalProperties' => false,
		];
	}

	public function canList(int $userId): bool
	{
		return Loader::includeModule('tasks');
	}

	public function canRun(int $userId): bool
	{
		return Loader::includeModule('tasks');
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!Loader::includeModule('tasks'))
		{
			throw new McpException('Tasks module is not available.');
		}

		$args['limit'] = $this->resolveLimit($args['limit'] ?? null);

		$request = SearchMessagesByTasksRequest::fromArray($args);

		try
		{
			return $this->messageByTaskSearch->search($request, $userId);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}
	}

	private function resolveLimit(mixed $raw): int
	{
		$value = is_numeric($raw) ? (int)$raw : self::DEFAULT_LIMIT;

		return max(1, min(self::MAX_LIMIT, $value));
	}
}
