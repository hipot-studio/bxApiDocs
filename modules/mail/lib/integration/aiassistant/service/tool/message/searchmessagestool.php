<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Integration\AiAssistant\Provider\MessageProvider;
use Bitrix\Mail\Helper\Dto\Message\SearchMessagesDto;
use Bitrix\Main\SystemException;

class SearchMessagesTool extends ToolContract
{
	public const ACTION_NAME = 'search_messages';

	public function __construct(
		private readonly MessageProvider $messageProvider,
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
			"Searches for email messages in a mailbox based on various criteria. "
			. "Returns message identifiers, subjects, senders, dates, and read status. "
			. "If mailboxId is not specified, searches in all user mailboxes."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'mailboxId' => [
					'type' => ['integer', 'null'],
					'description' => 'Identifier of the mailbox to search in. If not specified, searches in all user mailboxes.',
					'minimum' => 1,
				],
				'searchQuery' => [
					'type' => ['string', 'null'],
					'description' => 'Search query to find messages by subject, sender, or content.',
					'minLength' => 1,
				],
				'dateFrom' => [
					'type' => ['string', 'null'],
					'format' => 'date-time',
					'description' => "Start of the date range in 'Y/m/d H:i' format.",
				],
				'dateTo' => [
					'type' => ['string', 'null'],
					'format' => 'date-time',
					'description' => "End of the date range in 'Y/m/d H:i' format.",
				],
				'isSeen' => [
					'type' => ['boolean', 'null'],
					'description' => 'Filter by read status. True for read, false for unread, null for all.',
				],
				'hasAttachments' => [
					'type' => ['boolean', 'null'],
					'description' => 'Filter by attachments. True for with attachments, false without, null for all.',
				],
				'folder' => [
					'type' => ['string', 'null'],
					'description' => 'Folder name (e.g., INBOX, Sent). Null for all folders.',
				],
				'limit' => [
					'type' => ['integer', 'null'],
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
			$messages = $this->messageProvider->search($dto, $userId);
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
