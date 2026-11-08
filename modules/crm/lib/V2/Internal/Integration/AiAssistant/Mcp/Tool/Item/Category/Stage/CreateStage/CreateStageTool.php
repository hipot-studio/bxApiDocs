<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\Stage\CreateStage;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ObjectProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\StageService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\HexColorField;

final class CreateStageTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly StageService $stageService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'create_stage',
			description: <<<HEREDOC
				Create a new CRM kanban stage (pipeline column) in one CRM entity type.
				Always pass categoryId explicitly. Use list_stages first to inspect existing stage ids, colors, semantics, and sort values.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: 'Required exact CRM entity type identifier with stages enabled, not an item id. Get it from list_entity_types.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new IntegerProperty(
					id: 'categoryId',
					description: <<<HEREDOC
						Required category selector for stages.
						Pass a CRM pipeline id for deals and smart processes with pipelines enabled; get ids from list_categories.
						Pass null for smart processes without pipelines and for entity types without categories.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(true)
				,

				(new ObjectProperty(
					id: 'stage',
					description: <<<HEREDOC
						Required new CRM kanban stage definition.
						Choose an unused sort that preserves the P, S, F semantic group order shown by list_stages.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
					->setProperties([
						(new StringProperty(
							id: 'title',
							description: 'Required human-readable CRM kanban stage name.',
						))
							->setIsRequired(true)
							->setIsNullable(false)
						,
						(new StringProperty(
							id: 'color',
							description: <<<HEREDOC
								Optional visual color value for the CRM kanban stage in the UI.
								Omit it to generate a color automatically from semantics. If provided, use #RRGGBB format, for example #39A8EF.
							HEREDOC,
						))
							->setIsRequired(false)
							->setIsNullable(false)
							->setPattern(HexColorField::PATTERN)
						,
						(new StringProperty(
							id: 'semantics',
							description: <<<HEREDOC
								Required semantic group of the CRM kanban stage: P = in-progress stage, F = failed final stage.
								Use P for a regular working column.
							HEREDOC,
						))
							->setEnum([
								\Bitrix\Crm\PhaseSemantics::PROCESS,
								\Bitrix\Crm\PhaseSemantics::FAILURE,
							])
							->setIsRequired(true)
							->setIsNullable(false)
						,
						(new IntegerProperty(
							id: 'sort',
							description: 'Required unused positive integer that places P before S and F, or F after P and S. Get current values from list_stages.',
						))
							->setMinimum(1)
							->setIsRequired(true)
							->setIsNullable(false)
						,
					])
			])
		;
	}

	/**
	 * @param CreateStageToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->stageService->createStage(
			userId: $args->getUserId(),
			entityTypeId: $args->entityTypeId,
			categoryId: $args->categoryId,
			stage: $args->stage,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(stage: $result->get('stage'));
	}

	protected function getArgsDtoClass(): string
	{
		return CreateStageToolDto::class;
	}
}
