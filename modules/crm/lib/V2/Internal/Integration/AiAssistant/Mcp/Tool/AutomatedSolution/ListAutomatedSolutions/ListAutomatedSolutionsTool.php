<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AutomatedSolution\ListAutomatedSolutions;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\AutomatedSolutionService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ListAutomatedSolutionsTool extends AbstractTool
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
			name: 'list_automated_solutions',
			description: <<<HEREDOC
				Return CRM automated solutions (digital workplaces).
				Call this tool to get a valid automatedSolutionId before creating a smart process or binding a smart process to a workplace.
			HEREDOC,
		))
			->setProperties([
				(new StringProperty(
					id: 'query',
					description: 'Optional search string used to filter CRM automated solutions by title.',
				))
					->setIsRequired(false)
					->setIsNullable(false)
			])
		;
	}

	/**
	 * @param ListAutomatedSolutionsToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->automatedSolutionService->listAutomatedSolutions($args->getUserId(), $args->query);
		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(automatedSolutions: $result->get('automatedSolutions'));
	}

	protected function getArgsDtoClass(): string
	{
		return ListAutomatedSolutionsToolDto::class;
	}
}
