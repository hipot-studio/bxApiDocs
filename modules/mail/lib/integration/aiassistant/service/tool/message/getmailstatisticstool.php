<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\Dto\Message\MailStatisticsDto;
use Bitrix\Mail\Helper\Message\MessageStatistics;
use Bitrix\Main\SystemException;

class GetMailStatisticsTool extends ToolContract
{
	public const ACTION_NAME = 'get_mail_statistics';

	private const GENERIC_ERROR_MESSAGE = 'Could not get mail statistics. Try again later.';
	private const GENERIC_INPUT_ERROR_MESSAGE = 'Could not get mail statistics. Check the input parameters and try again.';

	private const SAFE_INPUT_ERROR_MESSAGES = [
		'Use either mailboxId or employeeId, not both.',
		"Invalid dateFrom. Use an ISO 8601 date like 'YYYY-MM-DD' or a full date-time.",
		"Invalid dateTo. Use an ISO 8601 date like 'YYYY-MM-DD' or a full date-time.",
		'dateFrom must be earlier than or equal to dateTo.',
	];

	private const SAFE_STATISTICS_ERROR_MESSAGES = [
		'employeeId must be a positive integer.',
		'mailboxId must be a positive integer.',
		'The selected mailbox is not available to the current user.',
	];

	public function __construct(
		private readonly MessageStatistics $messageStatistics,
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
			"Returns the count of incoming email messages "
			. "as { incomingCount: int }. "
			. "When dateFrom and/or dateTo are given, the count is restricted to that period; "
			. "otherwise it is the lifetime count. "
			. "If mailboxId and employeeId are omitted, the count is aggregated across all user mailboxes. "
			. "Use employeeId to count the selected employee's own mailboxes that are accessible to the current user. "
			. "Use either mailboxId or employeeId, not both. "
			. "An incoming message is one that lives in the mailbox incoming folder."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'mailboxId' => [
					'type' => 'integer',
					'description' =>
						'Identifier of the mailbox. Omit to aggregate across all user mailboxes. '
						. 'Use either mailboxId or employeeId, not both.',
					'minimum' => 1,
				],
				'employeeId' => [
					'type' => 'integer',
					'description' =>
						'Employee/user id whose own mailboxes should be counted. '
						. 'Only mailboxes accessible to the current user are included. '
						. 'Use either employeeId or mailboxId, not both.',
					'minimum' => 1,
				],
				'dateFrom' => [
					'type' => 'string',
					'description' =>
						'Start of the period. Use ISO 8601 date (YYYY-MM-DD) or date-time. '
						. 'A bare date starts at 00:00:00. Omit for a lifetime count.',
				],
				'dateTo' => [
					'type' => 'string',
					'description' =>
						'End of the period, inclusive. Use ISO 8601 date (YYYY-MM-DD) or date-time. '
						. 'A bare date ends at 23:59:59. Omit for a lifetime count.',
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
			$dto = MailStatisticsDto::fromArray($args);
		}
		catch (SystemException $e)
		{
			throw $this->createInputException($e);
		}

		try
		{
			return $this->messageStatistics->getStatistics($dto, $userId);
		}
		catch (SystemException $e)
		{
			throw $this->createStatisticsException($e);
		}
	}

	private function createInputException(SystemException $e): McpException
	{
		$message = $this->isSafeInputError($e->getMessage())
			? $e->getMessage()
			: self::GENERIC_INPUT_ERROR_MESSAGE
		;

		return new McpException($message, previous: $e);
	}

	private function createStatisticsException(SystemException $e): McpException
	{
		$message = $this->isSafeStatisticsError($e->getMessage())
			? $e->getMessage()
			: self::GENERIC_ERROR_MESSAGE
		;

		return new McpException($message, previous: $e);
	}

	private function isSafeStatisticsError(string $message): bool
	{
		return in_array($message, self::SAFE_STATISTICS_ERROR_MESSAGES, true);
	}

	private function isSafeInputError(string $message): bool
	{
		return in_array($message, self::SAFE_INPUT_ERROR_MESSAGES, true);
	}
}
