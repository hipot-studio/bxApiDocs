<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent;

abstract class BaseAgent
{
	protected static string $moduleId;

	/**
	 * @return string
	 */
	public static function run(): string
	{
		$result = (new static())->runInternal();

		if ($result->getIsRetry())
		{
			return static::class . '::run();';
		}

		return '';
	}

	/**
	 * Runs internal logic.
	 *
	 * @return RunResult
	 */
	abstract protected function runInternal(): RunResult;
}