<?php

namespace Bitrix\Sign\Item\Process;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Type\Process\LaunchItemStatus;

/**
 * One launch of a group: the employee the process is started for, the smart process item created for
 * them and the process itself. The identifier of the process is empty until the engine returns it,
 * so an empty workflowId on a queued row is the normal state, not a lost value.
 */
class LaunchItem implements Contract\Item
{
	public function __construct(
		public int $launchId,
		public int $employeeId,
		public LaunchItemStatus $status = LaunchItemStatus::Queued,
		public ?int $entityId = null,
		public ?string $workflowId = null,
		public ?string $errorMessage = null,
		public ?int $id = null,
	)
	{
	}

	public function getId(): int
	{
		return $this->id ?? 0;
	}
}
