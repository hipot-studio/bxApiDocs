<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Service;

interface GridAccessServiceInterface
{
	public function canRead(int $userId, int $entityId): bool;

	public function canUpdate(int $userId, int $entityId): bool;

	public function canDelete(int $userId, int $entityId): bool;

	public function canJoin(int $userId, int $entityId): bool;

	public function canLeave(int $userId, int $entityId): bool;
}
