<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\ListDealUserFieldForOpenLine;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\OpenLineService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use CCrmOwnerType;

final class ListDealUserFieldForOpenLineTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly OpenLineService $openLineService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'list_deal_user_field_for_open_line',
			description: <<<HEREDOC
				Allows you to get a list of deal fields that can be populated with values.
				To populate values, use `add_deal_user_field_value_from_open_line` tool.

				This tool is intended for external Open Channel users.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'dealId',
					description: 'CRM deal ID.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param ListDealUserFieldForOpenLineToolDto $args
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->openLineService->getEditableUserFieldList(
			userId: $args->getUserId(),
			entityTypeId: CCrmOwnerType::Deal,
			entityId: $args->dealId,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(fields: $result->get('fields') ?? []);
	}

	protected function getArgsDtoClass(): string
	{
		return ListDealUserFieldForOpenLineToolDto::class;
	}
}
