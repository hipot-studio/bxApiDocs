<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Facade\TracedLogger;

abstract class BaseCrmTool extends ToolContract
{
	public function __construct(TracedLogger $tracedLogger)
	{
		$this->setHelpers();

		parent::__construct($tracedLogger);
	}

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

	protected function setHelpers(): self
	{
		// This method can be overridden in child classes to set up helpers if needed

		return $this;
	}

	abstract protected function executeTool(int $userId, ...$args): string;
}
