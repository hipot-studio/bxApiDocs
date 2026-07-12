<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\Async;

abstract class AbstractMemberMessage extends AbstractStructureSyncMessage
{
	public function __construct(
		public readonly int $nodeId,
		public readonly int $userId,
		public readonly int $offset = 0,
	)
	{
	}

	public function getItemId(): string
	{
		return "member_{$this->nodeId}_{$this->userId}";
	}
}
