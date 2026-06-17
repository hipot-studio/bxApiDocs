<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\RecipientHelper;
use Bitrix\Main\SystemException;

class ListMailRecipientsTool extends ToolContract
{
	public const ACTION_NAME = 'list_mail_recipients';

	public function __construct(
		private readonly RecipientHelper $recipientHelper,
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
			"Searches the user's address book for recipients by name or email. "
			. "Use this to resolve a recipient before sending a message. "
			. "Without a query, returns the most recent contacts."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'query' => [
					'type' => 'string',
					'description' => 'Search query — name or email address (partial match). If omitted, returns recent contacts.',
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
		$query = (string)($args['query'] ?? '');

		try
		{
			$recipients = $this->recipientHelper->searchRecipients($query, $userId);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		return [
			'recipients' => $recipients,
		];
	}
}
