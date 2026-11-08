<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Timeline\Monitor;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Tells the monitor of the timeline that the activity is leaving every object it is bound to: row 7 of the owner
 * table STEP-DELETE (crm_activity.php:1349-1360).
 *
 * THE POSITION IS THE WHOLE POINT OF THE ROW (item 6 of section 13 of LBS-1): the monitor decides whether the
 * timeline of the object still has a suitable entry, and it decides that by looking at the storage. Asked after row 8
 * it would look at an activity whose row, bindings and communications are already gone, and answer about a different
 * portal. So this row stands above the deletion and the order 7 -> 8 -> 10 is not rearranged.
 *
 * THE CONDITION IS THAT THE SET OF BINDINGS IS A SET, which row 6 above answers. Legacy states it as
 * `is_array($arBindings)` and reaches it with a non-array only where the identifier is non-positive - the one thing
 * its reader answers false for - so an activity bound to nothing runs the row and tells nobody, while a run whose row
 * 6 never answered skips it.
 *
 * THE STEP HAS NO SEAM AND THERE IS NOTHING TO OBSERVE TODAY. The monitor is a final singleton of the module resolved
 * through the service locator, so nothing can stand in for it; and the method being called is a documented no-op -
 * "last activity cant decrease" ({@see Monitor::onActivityRemoveIfSuitable()}) - so the row performs no effect on any
 * portal as the product stands. It is declared all the same, because the owner table declares it and because the
 * position it holds is what the invariant above is about: the day the monitor answers that question, it has to be
 * asked here.
 *
 * @internal
 */
final class NotifyTimelineBeforeDelete implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The monitor
	 * is told about an activity leaving an object, so there is nothing to say.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'NotifyTimelineBeforeDelete';
	}

	/**
	 * See the note of the class: the set of the bindings has to be a set, which is not the same as being non-empty.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->getActivity()->getBindings() !== null;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		$monitor = Monitor::getInstance();
		foreach ($context->getActivity()->getBindings() ?? [] as $binding)
		{
			$identifier = self::identifierOf($binding);
			if ($identifier === null)
			{
				continue;
			}

			$monitor->onActivityRemoveIfSuitable($identifier, $activityId);
		}

		return $result;
	}

	/**
	 * An activity whose timeline could not be told is deleted all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The owner of a binding as an item identifier, or null for an owner no identifier can be made of. Rows of
	 * `b_crm_act_bind` carry owner types no longer defined, and legacy skips exactly those bindings here rather
	 * than failing over them (`ItemIdentifier::createFromArray()`, crm_activity.php:1372-1383): a row nobody can
	 * address names no timeline to tell, and the rest of the set is still told.
	 */
	private static function identifierOf(ActivityBinding $binding): ?ItemIdentifier
	{
		return ItemIdentifier::createByParams($binding->getOwnerType()->getId(), $binding->getOwnerId());
	}
}
