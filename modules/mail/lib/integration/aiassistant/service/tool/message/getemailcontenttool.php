<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\Message\MessageSearch;
use Bitrix\Main\SystemException;

class GetEmailContentTool extends ToolContract
{
	public const ACTION_NAME = 'get_email_content';

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
			"Returns a single email message with subject, from, to, cc, date, and "
			. "body (sanitized plain text). Use when you need the body, not just "
			. "metadata from search_emails. "
			. "Requires the message identifier from the search_emails tool."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'messageId' => [
					'type' => 'integer',
					'description' => 'Identifier of the mail message to get content for.',
					'minimum' => 1,
				],
			],
			'required' => ['messageId'],
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
		$messageId = (int)($args['messageId'] ?? 0);

		if ($messageId <= 0)
		{
			throw new McpException('Parameter messageId is required and must be a positive integer.');
		}

		try
		{
			$result = $this->messageSearch->getMessageContent(
				messageId: $messageId,
				userId: $userId,
			);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		return $result;
	}
}
