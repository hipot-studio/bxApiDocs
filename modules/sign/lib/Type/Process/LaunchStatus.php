<?php

namespace Bitrix\Sign\Type\Process;

/**
 * State of a launch group. The values are the stored ones (NORMATIVE ALG-01), so a new state gets
 * a new case instead of a reused value.
 */
enum LaunchStatus: string
{
	case Queued = 'queued';
	case Running = 'running';
	case Done = 'done';
	case Error = 'error';

	/**
	 * States of a group whose rows may still be waiting for their process, so the waiting term
	 * applies to it.
	 *
	 * @return list<self>
	 */
	public static function getUnfinished(): array
	{
		return [self::Queued, self::Running];
	}
}
