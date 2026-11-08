<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\CreateSmartProcess;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class CreateSmartProcessTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly SmartProcessService $smartProcessService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'create_smart_process',
			description: <<<HEREDOC
				Create a new smart process.
				Pass automatedSolutionId only when the new smart process should be bound to an existing CRM automated solution (digital workplace).
			HEREDOC,
		))
			->setProperties([
				(new StringProperty(
					id: 'title',
					description: 'Required human-readable name of the smart process to create.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new IntegerProperty(
					id: 'automatedSolutionId',
					description: <<<HEREDOC
						Optional automatedSolutionId of the target CRM digital workplace where the new smart process should be created and bound.
						Get it from list_automated_solutions.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param CreateSmartProcessToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->smartProcessService->createSmartProcess(
			userId: $args->getUserId(),
			title: (string)$args->title,
			automatedSolutionId: $args->automatedSolutionId,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(smartProcess: $result->get('smartProcess'));
	}

	protected function getArgsDtoClass(): string
	{
		return CreateSmartProcessToolDto::class;
	}
}
