<?php

namespace Bitrix\Sign\Type\Process;

/**
 * State the list of running processes is filtered by. The default one keeps the rows that still ask
 * something of the person looking at them: a group that has not started yet and a group whose row failed
 * both need an action, so hiding them among the finished ones would lose them.
 */
enum RunningProcessFilterState: string
{
	case InWork = 'inWork';
	case Completed = 'completed';

	public static function makeDefault(): self
	{
		return self::InWork;
	}

	public static function tryFromOrDefault(?string $value): self
	{
		return $value === null ? self::makeDefault() : (self::tryFrom($value) ?? self::makeDefault());
	}

	public function covers(RunningProcessStatus $status): bool
	{
		return match ($this)
		{
			self::Completed => $status === RunningProcessStatus::Completed,
			self::InWork => $status !== RunningProcessStatus::Completed,
		};
	}
}
