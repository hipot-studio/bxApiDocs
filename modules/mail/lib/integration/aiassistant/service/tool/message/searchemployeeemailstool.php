<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Message;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\Helper\RecipientHelper;
use Bitrix\Main\SystemException;

class SearchEmployeeEmailsTool extends ToolContract
{
	public const ACTION_NAME = 'search_employee_emails';

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
			"Searches portal employees' work email addresses by name or email. "
			. "Use this as a fallback when list_mail_recipients (address book) does not return the needed contact. "
			. "Returns matching employees with their work email addresses."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'query' => [
					'type' => 'string',
					'description' => 'Search query — employee name or email address. Required.',
					'minLength' => 1,
				],
			],
			'required' => ['query'],
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
			$employees = $this->recipientHelper->searchEmployees($query, $userId);
		}
		catch (SystemException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		return [
			'employees' => $employees,
		];
	}
}
