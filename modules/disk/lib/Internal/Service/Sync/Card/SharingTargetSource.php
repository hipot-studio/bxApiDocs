<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Card;

/**
 * Explicit rights of one object granted beyond the base rights of the storage, as the targets of its sharing.
 */
interface SharingTargetSource
{
	/**
	 * @return list<array{type: string, id: ?int, bySharing: bool}>
	 */
	public function explicit(int $objectId): array;
}
