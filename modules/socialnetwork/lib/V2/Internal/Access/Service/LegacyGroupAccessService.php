<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Service;

use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\Permission\GroupAccessController;
use Bitrix\Socialnetwork\Permission\GroupDictionary;

/**
 * Transitional access service for non-collab group types (TYPE=project, TYPE=group).
 *
 * After all groups are converted to collabs, this class can be safely deleted
 * and all usages replaced with ProjectAccessService directly.
 *
 * @see ProjectAccessService — target service for TYPE=collab
 */
class LegacyGroupAccessService implements GridAccessServiceInterface
{
	public function __construct(
		private readonly ProjectAccessService $projectAccessService,
		private readonly CollabRegistry $collabRegistry,
	)
	{
	}

	public function canRead(int $userId, int $entityId): bool
	{
		return $this->can(
			userId: $userId,
			entityId: $entityId,
			collabMethod: fn() => $this->projectAccessService->canRead($userId, $entityId),
			groupAction: GroupDictionary::VIEW,
		);
	}

	public function canUpdate(int $userId, int $entityId): bool
	{
		return $this->can(
			userId: $userId,
			entityId: $entityId,
			collabMethod: fn() => $this->projectAccessService->canUpdate($userId, $entityId),
			groupAction: GroupDictionary::UPDATE,
		);
	}

	public function canDelete(int $userId, int $entityId): bool
	{
		return $this->can(
			userId: $userId,
			entityId: $entityId,
			collabMethod: fn() => $this->projectAccessService->canDelete($userId, $entityId),
			groupAction: GroupDictionary::DELETE,
		);
	}

	public function canJoin(int $userId, int $entityId): bool
	{
		return $this->can(
			userId: $userId,
			entityId: $entityId,
			collabMethod: fn() => $this->projectAccessService->canJoin($userId, $entityId),
			groupAction: GroupDictionary::JOIN,
		);
	}

	public function canLeave(int $userId, int $entityId): bool
	{
		return $this->can(
			userId: $userId,
			entityId: $entityId,
			collabMethod: fn() => $this->projectAccessService->canLeave($userId, $entityId),
			groupAction: GroupDictionary::LEAVE,
		);
	}

	private function can(
		int $userId,
		int $entityId,
		\Closure $collabMethod,
		string $groupAction,
	): bool
	{
		if ($userId <= 0 || $entityId <= 0)
		{
			return false;
		}

		if ($this->collabRegistry->get($entityId) !== null)
		{
			return $collabMethod();
		}

		return GroupAccessController::can($userId, $groupAction, $entityId);
	}
}
