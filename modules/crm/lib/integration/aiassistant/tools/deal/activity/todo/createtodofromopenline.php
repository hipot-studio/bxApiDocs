<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Deal\Activity\ToDo;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\Activity\Entity\ToDo;
use Bitrix\Crm\Integration\AiAssistant\Helper\OpenLineService;
use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

final class CreateToDoFromOpenLine extends BaseCrmTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly OpenLineService $openLineService,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return 'create_deal_todo_from_openline';
	}

	public function getDescription(): string
	{
		return <<<HEREDOC
			Creates a new to-do activity in the CRM and links it to a specified deal.
			Used for planning and assigning tasks to a manager.

			This is a specialized version of the tool that is required for creating to-do items from open lines.
			It is used only if it is explicitly stated that the target user is an external user of open lines.

			The to-do is assigned to the current responsible manager of the deal
		HEREDOC;
	}

	public function getInputSchema(): array
	{
		$format = DateTime::getFormat();

		return [
			'type' => 'object',
			'properties' => [
				'dealId' => [
					'description' => 'ID of the CRM deal to which this to-do will be attached.',
					'type' => 'integer',
					'minimum' => 1,
				],
				'title' => [
					'description' => 'Title of the to-do. A brief description of what needs to be done (e.g., "Call the client back", "Send a commercial proposal").',
					'type' => 'string',
				],
				'description' => [
					'description' => 'Detailed description of what needs to be done. May include instructions, conversation context, or important details about the deal.',
					'type' => 'string',
				],
				'deadline' => [
					'description' => "Due date and time for the to-do. Format: '$format'. Used for deadline tracking.",
					'type' => 'string',
				],
			],
			'additionalProperties' => false,
			'required' => [
				'dealId',
				'title',
				'description',
				'deadline',
			],
		];
	}

	/**
	 * @param int $userId
	 * @param ...$args
	 * @return string
	 *
	 * @see CreateToDoDto
	 */
	protected function executeTool(int $userId, ...$args): string
	{
		$args = new CreateToDoDto($args);
		if ($args->hasValidationErrors())
		{
			return self::fail($args->getValidationErrors());
		}

		$result = $this->openLineService->createToDo(
			userId: $userId,
			entityTypeId: CCrmOwnerType::Deal,
			entityId: $args->dealId,
			title: $args->title,
			description: $args->description,
			deadline: $args->deadline,
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

		return 'Failed to create to-do from open lines for deal: ' . implode(', ', $errorMessages);
	}

	private static function success(ToDo $todo): string
	{
		return "To-do '{$todo->getSubject()}' (ID: {$todo->getId()}) has been successfully created and attached from open lines to the deal";
	}
}
