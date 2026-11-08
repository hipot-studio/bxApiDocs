<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AutomatedSolution\CreateAutomatedSolution;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\AutomatedSolutionService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class CreateAutomatedSolutionTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly AutomatedSolutionService $automatedSolutionService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'create_automated_solution',
			description: <<<HEREDOC
				Create a new CRM automated solution (digital workplace).
				Use this tool when an agent needs a new workplace.
			HEREDOC,
		))
			->setProperties([
				(new StringProperty(
					id: 'title',
					description: 'Required human-readable name of the automated solution to create, up to 255 characters.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
					->setMaxLength(CreateAutomatedSolutionToolDto::MAX_TITLE_LENGTH)
				,
			])
		;
	}

	/**
	 * @param CreateAutomatedSolutionToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->automatedSolutionService->addAutomatedSolution(
			userId: $args->getUserId(),
			title: $args->title,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(automatedSolution: $result->get('automatedSolution'));
	}

	protected function getArgsDtoClass(): string
	{
		return CreateAutomatedSolutionToolDto::class;
	}
}
