<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Activity;

use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Integration\VoxImplantManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityBindingRepository;

/**
 * Removing the record of a telephone conversation takes an administrator.
 *
 * A RESTRICTION OF THE DOMAIN, not a right. Nobody is granted anything here: the rule only takes the
 * deletion of one kind of activity away from users who otherwise may delete it. That is why it is asked
 * separately from the five operations - the pipeline of a delete asks this rule and nothing else, exactly
 * as legacy wired this one restriction into its delete and left the rights over the bound objects to the
 * caller.
 *
 * WHAT IT APPLIES TO: an activity of the telephony, which is one made by the call provider whose external
 * identifier carries the prefix of the telephony. Both halves are the predicate of the integration and it
 * is asked as such rather than restated here.
 *
 * WHO IS EXEMPT: whoever administers at least one of the kinds of objects the activity is bound to - one is
 * enough - and the system, which is what a subject that is not a user means. An activity with no bindings
 * left is judged by the owner column of its row, and the identifier in that column takes no part: only the
 * kind of object does, and the same holds for the bindings.
 *
 * @internal
 */
final class CallDeletionRestrictionRule
{
	private const OWNER_TYPE_ID_COLUMN = 'OWNER_TYPE_ID';

	private readonly ActivityBindingRepository $bindingRepository;

	public function __construct(?ActivityBindingRepository $bindingRepository = null)
	{
		$this->bindingRepository = $bindingRepository ?? new ActivityBindingRepository();
	}

	/**
	 * Whether the user is forbidden to delete this activity.
	 *
	 * A subject that is not a user is the system, and the system is never restricted: a caller acting on
	 * behalf of the portal - emptying the recycle bin, cleaning up volume, a provider deleting after itself
	 * - passes no user and keeps deleting.
	 */
	public function isDeletionRestricted(int $userId, int $activityId): bool
	{
		if ($userId <= 0 || $activityId <= 0)
		{
			return false;
		}

		$row = $this->readOwnerAndOriginColumns($activityId);
		if ($row === null || !VoxImplantManager::isActivityBelongsToVoximplant($row))
		{
			return false;
		}

		$userPermissions = Container::getInstance()->getUserPermissions($userId);
		foreach ($this->ownerTypeIds($activityId, $row) as $ownerTypeId)
		{
			if ($userPermissions->isAdminForEntity($ownerTypeId))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The kinds of objects the activity belongs to, each one once: its bindings, or the owner column of its
	 * row when no binding is left.
	 *
	 * @param array<string, mixed> $row
	 * @return int[]
	 */
	private function ownerTypeIds(int $activityId, array $row): array
	{
		$bindings = $this->bindingRepository->getByActivityId($activityId);
		if ($bindings === [])
		{
			return [(int)($row[self::OWNER_TYPE_ID_COLUMN] ?? 0)];
		}

		$typeIds = [];
		foreach ($bindings as $binding)
		{
			$typeIds[] = $binding->getOwnerType()->getId();
		}

		return array_values(array_unique($typeIds));
	}

	/**
	 * The three columns the rule judges by, or null when there is no row with that id.
	 *
	 * Read past the repository of activities on purpose: two of the three reach no model of the domain -
	 * the external identifier belongs to the provider of the activity and the owner column to the storage -
	 * and the storage boundary publishes no reader for either. Their home is a reader of the repository,
	 * and this stays the only place that names the columns until there is one.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readOwnerAndOriginColumns(int $activityId): ?array
	{
		$row = ActivityTable::getList([
			'select' => ['PROVIDER_ID', 'ORIGIN_ID', self::OWNER_TYPE_ID_COLUMN],
			'filter' => ['=ID' => $activityId],
		])->fetch();

		return is_array($row) ? $row : null;
	}
}
