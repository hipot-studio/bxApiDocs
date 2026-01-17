<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;

abstract class BaseImTool extends ToolContract
{
	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	protected function execute(int $userId, ...$args): string
	{
		return $this->executeTool($userId, ...$args);
	}

	abstract protected function executeTool(int $userId, ...$args): string;
}
