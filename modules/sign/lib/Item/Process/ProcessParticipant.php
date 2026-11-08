<?php

namespace Bitrix\Sign\Item\Process;

use Bitrix\Sign\Contract;

/**
 * A person the process is waiting for right now. The moment is the one their task appeared at, so the
 * interface can say how long the step has been held rather than when the process itself started.
 */
final class ProcessParticipant implements Contract\Item
{
	public function __construct(
		public readonly int $userId,
		public readonly ?int $holdingSinceTs = null,
	)
	{
	}
}
