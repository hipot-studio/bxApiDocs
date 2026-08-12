<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\BooleanProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessSettingsService;

/**
 * Base for MCP tools that switch a single boolean setting of a smart process on or off.
 *
 * Subclasses only declare the tool identity (name and description), the toggled flag
 * (its parameter name and description) and how to read and write it on the type. The
 * shared resolve-permissions-save flow lives here.
 */
abstract class AbstractToggleTool extends AbstractTool
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

	abstract protected function getToolName(): string;

	abstract protected function getToolDescription(): string;

	abstract protected function getFlagName(): string;

	abstract protected function getFlagDescription(): string;

	abstract protected function applyFlag(Type $type, bool $isEnabled): void;

	abstract protected function readFlag(Type $type): bool;

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: $this->getToolName(),
			description: $this->getToolDescription(),
		))
			->setProperties([
				(new IntegerProperty(
					'smartProcessId',
					'ENTITY_TYPE_ID of the smart process. Use search_dynamic_type to find it.',
				))
					->setIsRequired(true)
				,
				(new BooleanProperty(
					$this->getFlagName(),
					$this->getFlagDescription(),
				))
					->setIsRequired(true)
				,
			])
		;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$smartProcessId = (int)$args->smartProcessId;

		$resolved = $this->settingsService->resolveUpdatableType($smartProcessId, $args->getUserId());
		if ($resolved instanceof ToolResult)
		{
			return $resolved;
		}
		$type = $resolved;

		$this->applyFlag($type, (bool)$args->{$this->getFlagName()});

		$result = $type->save();
		if (!$result->isSuccess())
		{
			$messages = implode('; ', $result->getErrorMessages());

			return ToolResult::fail("Failed to update smart process: {$messages}");
		}

		return ToolResult::success(...[
			'smartProcessId' => $type->getEntityTypeId(),
			$this->getFlagName() => $this->readFlag($type),
		]);
	}
}
