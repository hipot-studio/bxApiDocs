<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Read;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Main\SystemException;

/**
 * The rule a context get adds to the read of an activity: the activity is bound to at least one object of the
 * entity type the route names.
 *
 * It is asked after the right on the activity, which it neither replaces nor extends: no owner is named and no
 * right on an object of the type is checked. {@see ActivityReader} asks it before it loads anything the select
 * names, with the bindings loaded in the one query a read of them makes - the only query the rule adds, and one
 * a read that selects the bindings makes anyway. {@see self::fitToSelect()} takes them back off where the caller
 * did not select them.
 *
 * @internal
 */
final class ActivityContext
{
	public function __construct(
		private readonly int $entityTypeId,
	)
	{
	}

	/**
	 * @throws SystemException where the bindings were not loaded, which {@see ActivityReader} rules out.
	 */
	public function admits(Activity $activity): bool
	{
		$bindings = $activity->getBindings();
		if ($bindings === null)
		{
			throw new SystemException('The bindings of the activity are not loaded');
		}

		foreach ($bindings as $binding)
		{
			if ($binding->getOwnerType()->getId() === $this->entityTypeId)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Leaves on the activity only what the caller selected: bindings loaded for the rule alone go back to null,
	 * as a read that did not load them leaves them. An empty select is the default set of the read, which loads
	 * the bindings.
	 *
	 * @param string[] $select the select of the caller.
	 */
	public function fitToSelect(Activity $activity, array $select): void
	{
		if ($select !== [] && !in_array(Activity::bindings, $select, true))
		{
			$activity->internalSet(Activity::bindings, null);
		}
	}
}
