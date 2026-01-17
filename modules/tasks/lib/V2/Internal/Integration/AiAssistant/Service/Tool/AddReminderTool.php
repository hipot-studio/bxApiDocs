<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Exception\Task\ReminderException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\ToolException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\AddReminderDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\ReminderService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\ReminderSchemaBuilder;

class AddReminderTool extends BaseTool
{
	public const ACTION_NAME = 'add_task_reminder';

	public function __construct(
		private readonly ReminderService $reminderService,
		ReminderSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Adds or updates a reminder for a task, specifying whom and when to remind.';
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = AddReminderDto::fromArray([...$args, 'userId' => $userId]);

		try
		{
			$this->validate($dto);

			$this->reminderService->add($dto, $userId);
		}
		catch (DtoValidationException|ReminderException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}
		catch (AccessDeniedException)
		{
			return $this->createFailureResponse('Access denied.');
		}
		catch (InvalidIdentifierException)
		{
			return $this->createFailureResponse('The provided task identifier is invalid.');
		}

		return 'Reminder successfully added.';
	}
}
