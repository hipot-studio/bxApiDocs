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
			. "Optional parameters can be omitted; omitted means no filter on that field. "
			. "If mailboxId is omitted, searches in all user mailboxes."
		;
	}

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
					'format' => 'date-time',
					'description' => "Start of the date range in 'Y/m/d H:i' format.",
				],
				'dateTo' => [
					'type' => 'string',
					'format' => 'date-time',
					'description' => "End of the date range in 'Y/m/d H:i' format.",
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
				'limit' => [
					'type' => 'integer',
					'description' => 'Maximum number of messages to return. Defaults to 25.',
					'minimum' => 1,
					'maximum' => 100,
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
		$dto = SearchMessagesDto::fromArray($args);

		try
		{
			$messages = $this->messageSearch->search($dto, $userId);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		return [
			'messages' => $messages,
		];
	}
}
