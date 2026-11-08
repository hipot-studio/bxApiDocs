<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Closure;
use Bitrix\Timeman\Security\UserPermissionsManager;

/**
 * Access rights of the v2 layer, answered in one place.
 *
 * V2 has no permission model of its own yet, so every answer here is a bridge to the legacy
 * permissions manager. What is injected is a resolver rather than the manager itself: the service
 * is a singleton while the identity it answers for is that of the current user of the request, so
 * the manager has to be taken anew per question - a manager captured once would go on answering
 * for whoever was current when the service was first built. The composition root
 * (config/services.php) is the one place the global user is read to build that resolver. When v2
 * grows a permission model, this is the one place the bridge is replaced.
 */
final class AccessService
{
	/**
	 * @param Closure(): UserPermissionsManager $permissionsManagerResolver
	 */
	public function __construct(
		private readonly Closure $permissionsManagerResolver,
	)
	{
	}

	/**
	 * Whether the current user may see the work time of that employee.
	 */
	public function canReadWorktime(int $userId): bool
	{
		return ($this->permissionsManagerResolver)()->canReadWorktime($userId);
	}

	/**
	 * The employees among those given whose work time the current user may see: the answer of
	 * canReadWorktime() for each of them, with the users he may read resolved once for the whole list.
	 *
	 * @param int[] $userIds positive ids, each once
	 * @return int[] the readable ones, in the order given
	 */
	public function filterReadableWorktimeUserIds(array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$permissionsManager = ($this->permissionsManagerResolver)();
		if ($permissionsManager->canReadWorktimeAll())
		{
			return array_values($userIds);
		}

		$readableUserIds = array_fill_keys($permissionsManager->getUserIdsAccessibleToRead(), true);

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => isset($readableUserIds[$userId]),
		));
	}
}
