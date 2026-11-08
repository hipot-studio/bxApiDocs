<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\Provider\Eventable\PingQueue;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\PingCapabilityInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Rebuilds the queue the reminders of the activity are sent out of, or empties it: row 40 of the owner table
 * STEP-UPDATE (crm_activity.php:1085-1095), checklist item C9.
 *
 * THE ROW RUNS ONLY WHERE SOMEBODY DECIDED THE QUEUE IS OUT OF STEP. That decision is
 * {@see PipelineContext::RUN_VALUE_PING_QUEUE_NEEDS_SYNC}, raised by the row resolving the reminder offsets (7), by
 * the branch of the change of the completion (23) and by the difference of the deadline (24) - a flag of a run, so
 * the question is whether it was raised and never what it holds. Legacy keeps the same fact in a local boolean of
 * its pipeline; an update nobody raised it in leaves the queue exactly as it stands.
 *
 * A REFUSAL OF THE PROVIDER EMPTIES THE QUEUE AND DOES NOT SKIP THE ROW. Where the provider says the queue is not to
 * be kept in step, legacy unregisters the activity rather than leaving the queue alone (:1092-1095): the reminders it
 * still held would go out for a deadline the activity no longer has. A provider that says nothing agrees, and so does
 * an activity with no provider at all - the answer of the base class of the legacy providers.
 *
 * THE STATE THE QUEUE IS DATED BY IS THE RE-READ ONE, THE SENTINEL INCLUDED. Legacy hands over the completion and the
 * deadline of the row it fetched, and a row without a deadline carries the sentinel
 * ({@see DeadlineSentinelGuard::columnOf()}); the queue recognizes the sentinel itself and empties itself over it,
 * which is exactly what it does over an empty value. The transit is not asked: an update does not carry the column
 * (row 3 takes it out), so the state is where the answer is.
 *
 * WHAT THE PROVIDER OF THE REFUSAL REALLY SEES, and it is a property of the contract of phase 4 rather than of this
 * row: the capability is defined over the INPUT of the operation ({@see PingCapabilityInterface}), so the wrapper of
 * a legacy provider builds its field array out of the payload and the columns of the input and this row hands it the
 * model of the input to match. Legacy asks its re-read snapshot instead - the one the row above has just written the
 * identifier of the calendar event into - so an update that does not name that column itself gets the answer of a
 * provider that cannot see it. The difference is unobservable for the one implementation that exists: it decides by
 * that very column and by no field of the model at all.
 *
 * @internal
 */
final class SyncPingQueue implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The queue is
	 * keyed by the activity, so there is nothing to rebuild.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'SyncPingQueue';
	}

	/**
	 * The one question legacy asks: has anything in this run put the queue of the reminders out of step.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->hasRunValue(PipelineContext::RUN_VALUE_PING_QUEUE_NEEDS_SYNC);
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		if (!self::isQueueSynchronized($context))
		{
			PingQueue::getInstance()->unregister($activityId);

			return $result;
		}

		$activity = $context->getActivity();
		PingQueue::getInstance()->register(
			$activityId,
			$activity->isCompleted() === true,
			(string)DeadlineSentinelGuard::columnOf($activity),
		);

		return $result;
	}

	/**
	 * An activity whose queue of reminders could not be rebuilt is written all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * Whether the queue of the reminders is kept in step with the deadline of this activity. Not implementing the
	 * capability means yes, and so does having no provider: that is the answer of the base class of the legacy
	 * providers and the answer legacy gives for an unknown provider (:1088).
	 */
	private static function isQueueSynchronized(PipelineContext $context): bool
	{
		$provider = $context->getProvider();
		if (!$provider instanceof PingCapabilityInterface)
		{
			return true;
		}

		return $provider->needSynchronizePingQueue($context->getInputActivity(), $context->getOperationContext());
	}
}
