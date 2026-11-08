<?php

namespace Bitrix\Crm\Service\UserPermissions;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Access\Activity\ActivityAccess;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Public\OwnerType;

/**
 * Whether the user may read, change, delete, complete or postpone an activity, and whether a new one may be
 * attached to a CRM object. Only these questions: preloading a batch, forgetting an activity and reading a
 * whole set stay on {@see ActivityAccess}, where the engine of the domain asks them.
 *
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->activity()
 */

final class Activity
{
	public function __construct(
		private readonly int $userId,
	)
	{
	}

	public function canRead(int $activityId): bool
	{
		return $this->access()->canRead($this->userId, $activityId);
	}

	public function canUpdate(int $activityId): bool
	{
		return $this->access()->canUpdate($this->userId, $activityId);
	}

	public function canDelete(int $activityId): bool
	{
		return $this->access()->canDelete($this->userId, $activityId);
	}

	public function canComplete(int $activityId): bool
	{
		return $this->access()->canComplete($this->userId, $activityId);
	}

	public function canPostpone(int $activityId): bool
	{
		return $this->access()->canPostpone($this->userId, $activityId);
	}

	/**
	 * Whether the user may attach a new activity to this CRM object.
	 *
	 * An owner no object can be addressed by is refused instead of building a binding of it.
	 */
	public function canAddToOwner(int $ownerEntityTypeId, int $ownerEntityId): bool
	{
		if ($ownerEntityTypeId <= 0 || $ownerEntityId <= 0)
		{
			return false;
		}

		return $this->access()->canAddToOwner(
			$this->userId,
			new ActivityBinding(OwnerType::fromId($ownerEntityTypeId), $ownerEntityId),
		);
	}

	/**
	 * The access model of the hit, with the decisions it has already taken: one instance answers the whole
	 * hit, so it is asked for and not kept here.
	 */
	private function access(): ActivityAccess
	{
		return Container::getInstance()->getActivityAccess();
	}
}
