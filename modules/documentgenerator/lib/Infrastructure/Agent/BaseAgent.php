<?php

namespace Bitrix\DocumentGenerator\Infrastructure\Agent;

use Bitrix\Main\Config\Option;

abstract class BaseAgent
{
	public static function run(): string
	{
		$agentClass = static::class;

		return match (static::getInstance()->execute()) {
			ExecuteResult::Continue => "{$agentClass}::run();",
			ExecuteResult::Done => '',
		};
	}

	abstract public function execute(): ExecuteResult;

	protected static function getInstance(): self
	{
		return new static();
	}

	protected function setDelayBeforeNextExecution(int $seconds): void
	{
		global $pPERIOD;

		$pPERIOD = $seconds;
	}
}
