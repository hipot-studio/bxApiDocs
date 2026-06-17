<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\Message\MessageActions;
use Bitrix\Main\SystemException;

class CreateCrmEmailActivityTool extends ToolContract
{
	public const ACTION_NAME = 'create_crm_email_activity';

	public function __construct(
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
			"Creates a CRM activity from a mail message. "
			. "Links the email to CRM entities (deals, leads, contacts, companies). "
			. "Requires the message identifier obtained from the search_emails tool."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'messageId' => [
					'type' => 'integer',
					'description' => 'Identifier of the mail message to create CRM activity from.',
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
			$result = MessageActions::createCrmActivity(
				messageId: $messageId,
				userId: $userId,
			);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();

			throw new McpException($errors[0]->getMessage());
		}

		return [
			'success' => true,
			'messageId' => $messageId,
		];
	}
}
