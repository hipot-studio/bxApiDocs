<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\Dto\Message\SearchMessagesDto;
use Bitrix\Mail\Helper\Message\MessageSearch;
use Bitrix\Main\SystemException;

class SearchEmailsTool extends ToolContract
{
	public const ACTION_NAME = 'search_emails';

	private const MAX_LIMIT = 100;

	public function __construct(
		private readonly MessageSearch $messageSearch,
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
			"Searches for email messages in a mailbox. "
			. "Returns an array of messages with id, subject, from, to, date, "
			. "isSeen, hasAttachments, url, and a 'bindings' array listing the "
			. "message's existing links (type: 'crm', 'task', 'chat', 'calendarEvent', "
			. "or 'blogPost' — feed post). Use bindings to detect existing links "
			. "before creating duplicates. "
			. "Results are sorted by date descending (newest first). "
			. "Also returns pagination metadata: totalCount, returnedCount, remainingCount, "
			. "hasMore, nextOffset, limit, and offset. "
			. "If hasMore is true, explicitly tell the user that only the current page is shown "
			. "and remainingCount more messages match; use nextOffset to load the next page. "
			. "Optional parameters can be omitted; omitted means no filter on that field. "
			. "If mailboxId is omitted, searches in all user mailboxes. "
			. "Use 'bindings' to keep only messages that have at least one listed binding type. "
			. "Use bindings ['NO_BIND'] to find messages without any binding. "
			. "Use 'excludeBindings' to drop messages that have a binding of any listed type "
			. "(e.g. ['TASKS_TASK'] returns messages that have no task linked — other links are allowed)."
		;
	}

	/*
	 * 'unanswered' and 'classification' are deliberately left out of the schema: the first scans the whole
	 * mailbox (see MessageFilter::addUnanswered), the second has nothing to match until labelling ships.
	 * Hiding a filter only keeps it away from the model: arguments are not validated against the schema, so
	 * an explicitly passed key still reaches SearchMessagesDto.
	 */
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'mailboxId' => [
					'type' => 'integer',
					'description' => 'Identifier of the mailbox to search in. Omit to search in all user mailboxes.',
					'minimum' => 1,
				],
				'searchQuery' => [
					'type' => 'string',
					'description' => 'Search query to find messages by subject, sender, or content.',
					'minLength' => 1,
				],
				'dateFrom' => [
					'type' => 'string',
					'description' =>
						'Start of the date range. Use ISO 8601 date (YYYY-MM-DD) or date-time. '
						. 'A bare date starts at 00:00:00.',
				],
				'dateTo' => [
					'type' => 'string',
					'description' =>
						'End of the date range, inclusive. Use ISO 8601 date (YYYY-MM-DD) or date-time. '
						. 'A bare date ends at 23:59:59.',
				],
				'isSeen' => [
					'type' => 'boolean',
					'description' => 'Filter by read status. True for read, false for unread. Omit for all.',
				],
				'hasAttachments' => [
					'type' => 'boolean',
					'description' => 'Filter by attachments. True for with attachments, false without. Omit for all.',
				],
				'folder' => [
					'type' => 'string',
					'description' => 'Folder name (e.g., INBOX, Sent). Omit for all folders.',
				],
				'bindings' => [
					'type' => 'array',
					'description' =>
						"Keep only messages that have a binding of any listed entity type. "
						. "Use ['NO_BIND'] to find messages without any binding. "
						. "Omit for no binding filter.",
					'items' => [
						'type' => 'string',
						'enum' => SearchMessagesDto::ALLOWED_BINDINGS,
					],
					'uniqueItems' => true,
					'minItems' => 1,
				],
				'excludeBindings' => [
					'type' => 'array',
					'description' =>
						"Drop messages that have a binding of any listed entity type. "
						. "Example: ['TASKS_TASK'] returns messages without a linked task (other links are allowed). "
						. "Omit for no exclusion.",
					'items' => [
						'type' => 'string',
						'enum' => SearchMessagesDto::ALLOWED_EXCLUDE_BINDINGS,
					],
					'uniqueItems' => true,
					'minItems' => 1,
				],
				'limit' => [
					'type' => 'integer',
					'description' => 'Maximum number of messages to return. Defaults to ' . SearchMessagesDto::DEFAULT_LIMIT . ', max ' . self::MAX_LIMIT . '.',
					'minimum' => 1,
					'maximum' => self::MAX_LIMIT,
				],
				'offset' => [
					'type' => 'integer',
					'description' => 'Offset by matching messages. Use nextOffset from the previous response when hasMore is true.',
					'minimum' => 0,
				],
			],
			'required' => [],
			'additionalProperties' => false,
		];
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			$args['limit'] = $this->resolveLimit($args['limit'] ?? null);
			$args['offset'] = $this->resolveOffset($args['offset'] ?? null);

			$dto = SearchMessagesDto::fromArray($args);
			$messages = $this->messageSearch->search($dto, $userId);
			$totalCount = $this->messageSearch->count($dto, $userId);
			$returnedCount = count($messages);
			$remainingCount = max(0, $totalCount - ($dto->offset + $returnedCount));
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		return [
			'messages' => $messages,
			'totalCount' => $totalCount,
			'returnedCount' => $returnedCount,
			'remainingCount' => $remainingCount,
			'hasMore' => $remainingCount > 0,
			'nextOffset' => $remainingCount > 0
				? $dto->offset + $returnedCount
				: null,
			'limit' => $dto->limit,
			'offset' => $dto->offset,
		];
	}

	private function resolveLimit(mixed $raw): int
	{
		$value = is_numeric($raw) ? (int)$raw : SearchMessagesDto::DEFAULT_LIMIT;

		return max(1, min(self::MAX_LIMIT, $value));
	}

	private function resolveOffset(mixed $raw): int
	{
		$value = is_numeric($raw) ? (int)$raw : 0;

		return max(0, $value);
	}
}
