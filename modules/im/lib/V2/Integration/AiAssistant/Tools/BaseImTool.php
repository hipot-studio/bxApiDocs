<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\ToolExecutionNotImplementedException;
use Bitrix\Im\V2\Integration\AiAssistant\ToolScope;

abstract class BaseImTool extends ToolContract
{
	abstract protected function getScope(): ToolScope;

	/**
	 * Tool-level gate. ToolManager::callTool() and Mcp::indexAction() (module aiassistant)
	 * resolve tools from the cached DefinitionDto::tools map and consult tool-level
	 * canList()/canRun(), so a tool coming from a stale 24h registry cache must still
	 * be gated by the same option as its scope in ImToolSet::getUsesTools().
	 */
	public function canList(int $userId): bool
	{
		return $this->getScope()->isEnabled();
	}

	public function canRun(int $userId): bool
	{
		return $this->getScope()->isEnabled();
	}

	protected function execute(int $userId, ...$args): string
	{
		return $this->executeTool($userId, ...$args);
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		return $this->executeStructuredTool($userId, ...$args);
	}

	protected function executeTool(int $userId, ...$args): string
	{
		throw new ToolExecutionNotImplementedException('Tool must implement executeTool or executeStructuredTool.');
	}

	protected function executeStructuredTool(int $userId, ...$args): array
	{
		throw new ToolExecutionNotImplementedException('Tool must implement executeTool or executeStructuredTool.');
	}
}
