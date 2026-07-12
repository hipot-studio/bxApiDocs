<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\StructureSync;

class CollabRelationInfo
{
	public function __construct(
		public readonly int $entityId,
		public readonly int $createdBy,
	)
	{
	}
}
