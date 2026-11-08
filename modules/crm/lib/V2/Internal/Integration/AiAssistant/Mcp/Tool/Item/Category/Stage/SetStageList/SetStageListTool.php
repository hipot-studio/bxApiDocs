<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\Stage\SetStageList;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ArrayProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ObjectProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\StageService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\HexColorField;

final class SetStageListTool extends AbstractTool
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
			name: 'set_stage_list',
			description: <<<HEREDOC
				Replace the list of CRM kanban stages (pipeline columns) in one CRM stage scope.
				Each stage must include title and semantics; color is optional. The implementation recalculates sort automatically; do not send id or sort.
				Process stages (P) must go first, then success stages (S), and failure stages (F) must go last.
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
						Required category selector for the stage list.
						Pass a CRM pipeline id for deals and smart processes with pipelines enabled; get ids from list_categories.
						Pass null for smart processes without pipelines and for entity types without categories.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(true)
				,
				(new ArrayProperty(
					id: 'stages',
					description: <<<HEREDOC
						Required final list of CRM kanban stages (pipeline columns).
						Include at least one P, one S, and one F semantics value. Put P stages first, then S stages, then F stages.
					HEREDOC,
				))
					->setArrayItemProperty(
						(new ObjectProperty(
							id: 'stage',
							description: <<<HEREDOC
								Desired CRM kanban stage definition in final order.
								The DTO accepts title, optional color, and semantics only.
							HEREDOC,
						))
							->setProperties([
								(new StringProperty(
									id: 'title',
									description: 'Required non-empty CRM kanban stage name.',
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
										Required semantic group of the CRM kanban stage: P = in-progress stage, S = successful final stage, F = failed final stage.
										The submitted list must contain all three semantic groups.
									HEREDOC,
								))
									->setEnum([
										\Bitrix\Crm\PhaseSemantics::PROCESS,
										\Bitrix\Crm\PhaseSemantics::SUCCESS,
										\Bitrix\Crm\PhaseSemantics::FAILURE,
									])
									->setIsRequired(true)
									->setIsNullable(false)
								,
							])
					)
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param SetStageListToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->stageService->setStageList(
			userId: $args->getUserId(),
			entityTypeId: $args->entityTypeId,
			categoryId: $args->categoryId,
			stages: $args->stages,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(stages: $result->get('stages'));
	}

	protected function getArgsDtoClass(): string
	{
		return SetStageListToolDto::class;
	}
}
