<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Read;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityCommunication;
use Bitrix\Crm\V2\Public\OwnerType;
use Closure;

/**
 * Hides the address and the client of every communication whose client the user may not read.
 *
 * The rule is the one CRM already shows a communication by
 * ({@see \CCrmActivity::MaskCommunicationForUser()}): a communication attributed to an unreadable CRM object
 * keeps its channel and loses the address and the object, while one attributed to no object is shown as it
 * is. The element itself stays either way.
 *
 * It follows the right to the activity and never replaces it: only the activities the user may read are
 * handed over.
 *
 * The permission attributes of the clients of the whole set are preloaded per kind of object, so each client
 * is asked about once.
 *
 * Nothing is written: the masked set lands on the model through its hydration path, which marks no field as
 * changed.
 *
 * @internal
 */
final class ActivityCommunicationMasker
{
	private readonly Closure $permissionsOf;

	/**
	 * @param Closure|null $permissionsOf `function (int $userId): UserPermissions` - the permission facade the
	 *        clients are checked through; null takes the one of the container.
	 */
	public function __construct(?Closure $permissionsOf = null)
	{
		$this->permissionsOf = $permissionsOf
			?? static fn(int $userId): UserPermissions => Container::getInstance()->getUserPermissions($userId);
	}

	/**
	 * Masks the communications of the given activities in place. An activity whose communications were not
	 * loaded is left as it is.
	 */
	public function mask(int $userId, Activity ...$activities): void
	{
		$clients = self::clientsOf($activities);
		if ($clients === [])
		{
			return;
		}

		$readable = $this->readableClients(($this->permissionsOf)($userId), $clients);

		foreach ($activities as $activity)
		{
			$communications = $activity->getCommunications();
			if ($communications === null)
			{
				continue;
			}

			$shown = [];
			foreach ($communications as $communication)
			{
				$isHidden =
					self::hasClient($communication)
					&& !isset($readable[$communication->getEntityType()->getId()][$communication->getEntityId()])
				;
				$shown[] = $isHidden ? self::masked($communication) : $communication;
			}

			$activity->internalSet(Activity::communications, $shown);
		}
	}

	/**
	 * @param array<int, array<int, int>> $clients entity ids by entity type id
	 * @return array<int, array<int, true>> the clients the user may read, as a set
	 */
	private function readableClients(UserPermissions $permissions, array $clients): array
	{
		$itemPermissions = $permissions->item();

		$readable = [];
		foreach ($clients as $entityTypeId => $entityIds)
		{
			$itemPermissions->preloadPermissionAttributes($entityTypeId, array_values($entityIds));

			foreach ($entityIds as $entityId)
			{
				if ($itemPermissions->canRead($entityTypeId, $entityId))
				{
					$readable[$entityTypeId][$entityId] = true;
				}
			}
		}

		return $readable;
	}

	/**
	 * @param Activity[] $activities
	 * @return array<int, array<int, int>> entity ids by entity type id, each client once
	 */
	private static function clientsOf(array $activities): array
	{
		$clients = [];
		foreach ($activities as $activity)
		{
			foreach ($activity->getCommunications() ?? [] as $communication)
			{
				if (self::hasClient($communication))
				{
					$entityId = $communication->getEntityId();
					$clients[$communication->getEntityType()->getId()][$entityId] = $entityId;
				}
			}
		}

		return $clients;
	}

	private static function hasClient(ActivityCommunication $communication): bool
	{
		return $communication->getEntityType()->getId() > 0 && $communication->getEntityId() > 0;
	}

	private static function masked(ActivityCommunication $communication): ActivityCommunication
	{
		return new ActivityCommunication(
			$communication->getType(),
			'',
			OwnerType::fromId(OwnerType::UNDEFINED),
			0,
		);
	}
}
