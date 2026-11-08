<?php

namespace Bitrix\Sign\Item\Process;

use Bitrix\Sign\Contract;

/**
 * The process a signing document was created by, as the card of the document names it. The identifier of
 * the process comes from the row of the launch group and not from the engine: the row is the only place
 * that ties the element of the smart process to the process that ran on it.
 */
final class ProcessOrigin implements Contract\Item
{
	public function __construct(
		public readonly string $workflowId,
		public readonly int $entityId,
		public readonly string $name,
		public readonly string $url,
	)
	{
	}
}
