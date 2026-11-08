<?php

namespace Bitrix\Sign\Item\Process;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Type;
use Bitrix\Sign\Type\Process\LaunchStatus;

/**
 * A group of process launches made by one press of the start button. The composition of the group is
 * kept by its rows, not by the started processes: the rows exist from the moment the group is queued,
 * while the identifier of a process appears only after the engine has started it.
 */
class Launch implements Contract\Item, Contract\Item\ItemWithOwner
{
	public function __construct(
		public int $createdById,
		public int $templateId,
		public int $entityTypeId,
		public int $memberCount,
		public Type\DateTime $queuedAt,
		public Type\DateTime $expiresAt,
		public LaunchStatus $status = LaunchStatus::Queued,
		public array $parameters = [],
		public ?int $id = null,
	)
	{
	}

	public function getId(): int
	{
		return $this->id ?? 0;
	}

	public function getOwnerId(): int
	{
		return $this->createdById;
	}
}
