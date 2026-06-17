<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\Message\MessageSearch;
use Bitrix\Main\SystemException;

class GetEmailThreadTool extends ToolContract
{
	public const ACTION_NAME = 'get_email_thread';

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
			"Returns the full email thread (conversation chain) for a message. "
			. "Each thread entry has subject, from, to, cc, date, and body "
			. "(sanitized plain text), sorted chronologically. "
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
					'description' => 'Identifier of any message in the thread.',
					'minimum' => 1,
				],
				'limit' => [
					'type' => 'integer',
					'description' => 'Maximum number of messages in the thread to return. Defaults to 20.',
					'minimum' => 1,
					'maximum' => 50,
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
		$limit = isset($args['limit']) ? (int)$args['limit'] : 20;

		if ($messageId <= 0)
		{
			throw new McpException('Parameter messageId is required and must be a positive integer.');
		}

		try
		{
			$result = $this->messageSearch->getMessageThread(
				messageId: $messageId,
				userId: $userId,
				limit: $limit,
			);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		return $result;
	}
}
