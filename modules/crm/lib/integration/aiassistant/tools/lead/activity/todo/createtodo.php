<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Lead\Activity\ToDo;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\Activity\Entity\ToDo;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\AiAssistant\Helper\TimelineService;
use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

final class CreateToDo extends BaseCrmTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly TimeLineService $timeLineService,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return 'create_lead_todo';
	}

	public function getDescription(): string
	{
		return <<<HEREDOC
			Creates a new to-do activity in the CRM and links it to a specified lead.
			Used for planning and assigning tasks to a manager.
		HEREDOC;
	}

	public function getInputSchema(): array
	{
		$format = DateTime::getFormat();

		return [
			'type' => 'object',
			'properties' => [
				'leadId' => [
					'description' => 'ID of the CRM lead to which this to-do will be attached.',
					'type' => 'integer',
					'minimum' => 1,
				],
				'title' => [
					'description' => 'Title of the to-do. A brief description of what needs to be done (e.g., "Call the client back", "Send a commercial proposal").',
					'type' => 'string',
				],
				'description' => [
					'description' => 'Detailed description of what needs to be done. May include instructions, conversation context, or important details about the lead.',
					'type' => 'string',
				],
				'deadline' => [
					'description' => "Due date and time for the to-do. Format: '$format'. Used for deadline tracking.",
					'type' => 'string',
				],
			],
			'additionalProperties' => false,
			'required' => [
				'leadId',
				'title',
				'description',
				'deadline'
			],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$args = new CreateToDoDto($args);
		if ($args->hasValidationErrors())
		{
			return self::fail($args->getValidationErrors());
		}

		$permissions = Container::getInstance()
			->getUserPermissions($userId)
			->item()
		;

		if (!$permissions->canUpdate(CCrmOwnerType::Lead, $args->leadId))
		{
			return self::fail(ErrorCode::getAccessDeniedError());
		}

		$result = $this->timeLineService->createToDo(
			entityTypeId: CCrmOwnerType::Lead,
			entityId: $args->leadId,
			title: $args->title,
			description: $args->description,
			deadline: $args->deadline,
			responsibleId: $userId,
		);

		if (!$result->isSuccess())
		{
			return self::fail($result->getErrorCollection());
		}

		return self::success($result->getData()['todo']);
	}

	private static function fail(ErrorCollection|Error $errorCollection): string
	{
		$errorMessages = match (true) {
			$errorCollection instanceof ErrorCollection => array_map(static fn (Error $error) => $error->getMessage(), $errorCollection->toArray()),
			$errorCollection instanceof Error => [$errorCollection->getMessage()],
		};

		return 'Failed to create to-do for lead: ' . implode(', ', $errorMessages);
	}

	private static function success(ToDo $todo): string
	{
		return "To-do '{$todo->getSubject()}' (ID: {$todo->getId()}) has been successfully created and attached to the lead";
	}
}
