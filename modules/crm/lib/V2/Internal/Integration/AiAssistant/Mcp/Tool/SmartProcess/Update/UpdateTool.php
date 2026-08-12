<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\Update;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessSettingsService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class UpdateTool extends AbstractTool
{
	private readonly SmartProcessSettingsService $settingsService;

	public function __construct(
		TracedLogger $tracedLogger,
		?SmartProcessSettingsService $settingsService = null,
	)
	{
		parent::__construct($tracedLogger);
		$this->settingsService = $settingsService ?? new SmartProcessSettingsService();
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'update_smart_process',
			description: 'Updates attributes of a CRM smart process (dynamic type).'
				. ' Currently supports renaming.'
				. ' Use search_dynamic_type to find the smartProcessId (ENTITY_TYPE_ID) first.',
		))
			->setProperties([
				(new IntegerProperty(
					'smartProcessId',
					'ENTITY_TYPE_ID of the smart process. Use search_dynamic_type to find it.',
				))
					->setIsRequired(true)
				,
				(new StringProperty(
					'title',
					'New name for the smart process.',
				))
					->setIsRequired(true)
				,
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return UpdateToolDto::class;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		/** @var UpdateToolDto $args */
		$smartProcessId = $args->smartProcessId;
		$title = trim((string)$args->title);

		$resolved = $this->settingsService->resolveUpdatableType((int)$smartProcessId, $args->getUserId());
		if ($resolved instanceof ToolResult)
		{
			return $resolved;
		}
		$type = $resolved;

		$type->setTitle($title);
		$result = $type->save();

		if (!$result->isSuccess())
		{
			$messages = implode('; ', $result->getErrorMessages());

			return ToolResult::fail("Failed to update smart process: {$messages}");
		}

		return ToolResult::success(
			smartProcessId: $type->getEntityTypeId(),
			title: (string)$type->getTitle(),
		);
	}
}
