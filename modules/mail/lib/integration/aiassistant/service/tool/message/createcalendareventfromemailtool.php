<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\Message\MessageActions;
use Bitrix\Main\SystemException;

class CreateCalendarEventFromEmailTool extends ToolContract
{
	public const ACTION_NAME = 'create_calendar_event_from_email';

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
			"Creates a calendar event from a mail message and links it to the message. "
			. "The event name defaults to the email subject. "
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
					'description' => 'Identifier of the mail message to create calendar event from.',
					'minimum' => 1,
				],
				'dateFrom' => [
					'type' => 'string',
					'description' => "Event start date in 'Y-m-d H:i:s' format (e.g. '2026-04-15 10:00:00').",
					'minLength' => 1,
				],
				'dateTo' => [
					'type' => 'string',
					'description' => "Event end date in 'Y-m-d H:i:s' format (e.g. '2026-04-15 11:00:00').",
					'minLength' => 1,
				],
				'name' => [
					'type' => ['string', 'null'],
					'description' => 'Event name. Defaults to the email subject if not specified.',
				],
				'description' => [
					'type' => ['string', 'null'],
					'description' => 'Event description.',
				],
			],
			'required' => ['messageId', 'dateFrom', 'dateTo'],
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

		$dateFrom = (string)($args['dateFrom'] ?? '');
		$dateTo = (string)($args['dateTo'] ?? '');

		if ($dateFrom === '' || $dateTo === '')
		{
			throw new McpException('Parameters dateFrom and dateTo are required.');
		}

		try
		{
			$result = MessageActions::createCalendarEvent(
				messageId: $messageId,
				userId: $userId,
				dateFrom: $dateFrom,
				dateTo: $dateTo,
				name: $args['name'] ?? null,
				description: $args['description'] ?? null,
			);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new McpException($result->getErrors()[0]->getMessage());
		}

		$data = $result->getData();

		return [
			'success' => true,
			'eventId' => $data['eventId'],
			'messageId' => $messageId,
		];
	}
}
