<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

abstract class BaseAgent
{
	abstract public static function run(): string;

	public static function next(): string
	{
		return static::class . "::run();";
	}
}
