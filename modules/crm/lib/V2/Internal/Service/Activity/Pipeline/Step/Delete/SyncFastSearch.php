<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\Activity\FastSearch\Sync\Monitor;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Takes the deleted activity out of the denormalized table the interface finds the nearest activities of an object by:
 * row 18 of the owner table STEP-DELETE (crm_activity.php:1504), checklist item C7.10.
 *
 * THE ROW IS UNCONDITIONAL AND THE ONE GATE THERE IS BELONGS TO THE MONITOR. It decides by the moment of creation of
 * the activity whether the table ever kept a row for it - the table holds a window of the recent activities - and a
 * removal outside that window is no work at all ({@see Monitor::onActivityDelete()}). Lifting that question up here
 * would be a second copy of a rule the monitor owns.
 *
 * THE MOMENT OF CREATION IS THE ONE THING HANDED OVER, because it is the one thing read. Legacy passes its whole
 * field array and the monitor touches `CREATED` alone; the pipeline of a delete already narrows such a call once, in
 * row 10, for the same reason. Everything else the table keeps - the person responsible, the deadline, the kind of the
 * row - matters to a write and not to a removal, which is keyed by the activity.
 *
 * THE ROW OF THE ACTIVITY IS ALREADY GONE, erased by row 8, so the moment comes off the model of the run - the state
 * row 2 read.
 *
 * @internal
 */
final class SyncFastSearch implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The row of
	 * the table is keyed by the activity, so there is nothing to remove.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	private const COLUMN_CREATED = 'CREATED';

	public function getName(): string
	{
		return 'SyncFastSearch';
	}

	/**
	 * Every delete is handed to the monitor. See the note of the class on why its gate is not asked here.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		Monitor::getInstance()->onActivityDelete($activityId, [
			self::COLUMN_CREATED => (string)$context->getActivity()->getCreatedTime(),
		]);

		return $result;
	}

	/**
	 * An activity that stayed in the table of the fast search is deleted all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
