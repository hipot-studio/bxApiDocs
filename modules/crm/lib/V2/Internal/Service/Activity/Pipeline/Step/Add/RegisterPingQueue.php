<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Activity\Provider\Eventable\PingOffset;
use Bitrix\Crm\Activity\Provider\Eventable\PingQueue;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CompletionForStatistics;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\PingCapabilityInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Writes the reminders of a new activity down and puts them into the queue they are sent out of: step 29 of the
 * owner table of an add (crm_activity.php:387-394), checklist item C7.11.
 *
 * TWO WRITES AND ONLY THE SECOND OF THEM IS ASKED ABOUT. The offsets themselves are always stored, and the queue
 * of the moments they are due at is filled only where the provider of the activity agrees; a provider that says
 * nothing agrees, and so does an activity with no provider at all
 * ({@see PingCapabilityInterface::needSynchronizePingQueue()}). The only refusal in the product is the provider
 * that lets the calendar event of the activity do the reminding instead.
 *
 * THE OFFSETS ARE READ, NEVER RESOLVED AGAIN. Their producer is the step resolving them ten rows above, which
 * left them in {@see PipelineContext::RUN_VALUE_PING_OFFSETS}; the offsets of the model are a different value -
 * they are what the caller named, and an activity whose caller named none carries nothing there while the
 * defaults of its provider are what legacy really registers. Resolving them a second time here would make the
 * step disagree with the light counter of the same run.
 *
 * THE DEADLINE IS READ THROUGH THE GUARD, and this is the second of the two places item C7.11 is about: legacy
 * reads `$arFields['DEADLINE']` blind and gets a warning for an add whose deadline nobody gave (section 15 item 7
 * of LBS-1). The question is asked about the run rather than about the column
 * ({@see DeadlineSentinelGuard::isGivenToTheRun()}), because that is the one answering as legacy does on an add: an
 * activity of an incoming channel keeps the sentinel in the column for the write while legacy has no key at all.
 * No deadline hands the queue nothing, and the queue then unregisters whatever it held - exactly what legacy
 * arrives at through its own empty check. Two halves of this read have no observable difference of their own,
 * because the queue recognizes the sentinel itself; what the guarded read really buys is that a value which is no
 * date at all answers "no deadline" instead of throwing out of a step of the tail.
 *
 * @internal
 */
final class RegisterPingQueue implements PipelineStepInterface
{
	/**
	 * There are no reminders to register for an activity that has no row yet. Legacy cannot reach this state: it
	 * interrupts an add on a failed insert.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_PING_QUEUE_WITHOUT_ACTIVITY';

	public function getName(): string
	{
		return 'RegisterPingQueue';
	}

	/**
	 * The gate of legacy: an activity that is not complete and really has reminders to register.
	 *
	 * THE COMPLETION IS THE ONE THE STATISTICS REFINED. Legacy computes the predicate once (:275-279) and reads
	 * it here as well, so an activity marked complete with a status of waiting counts as uncomplete and does get
	 * its reminders - {@see CompletionForStatistics} is that one definition.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if (CompletionForStatistics::isComplete($context->getActivity()))
		{
			return false;
		}

		return self::offsetsOf($context) !== [];
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		PingOffset::getInstance()->register($activityId, self::offsetsOf($context));

		if (self::isQueueSynchronized($context))
		{
			// The second argument is the completion, and legacy hands over a plain no: the gate above has
			// already answered that question for the whole step.
			PingQueue::getInstance()->register($activityId, false, self::deadlineOf($context));
		}

		return new Result();
	}

	/**
	 * An activity whose reminders could not be registered is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The offsets the run works with, or none where the step resolving them did not run at all.
	 *
	 * @return int[]
	 */
	private static function offsetsOf(PipelineContext $context): array
	{
		if (!$context->hasRunValue(PipelineContext::RUN_VALUE_PING_OFFSETS))
		{
			return [];
		}

		$offsets = $context->getRunValue(PipelineContext::RUN_VALUE_PING_OFFSETS);

		return is_array($offsets) ? $offsets : [];
	}

	/**
	 * Whether the queue of the reminders is kept in step with the deadline of this activity. Not implementing the
	 * capability means yes, and so does having no provider: that is the answer of the base class of the legacy
	 * providers and the answer legacy gives for an unknown provider.
	 */
	private static function isQueueSynchronized(PipelineContext $context): bool
	{
		$provider = $context->getProvider();
		if (!$provider instanceof PingCapabilityInterface)
		{
			return true;
		}

		return $provider->needSynchronizePingQueue($context->getActivity(), $context->getOperationContext());
	}

	/**
	 * The deadline the reminders are counted back from, as the text of the site, or null where the run carries
	 * none. The guarded read: see the note of the class.
	 */
	private static function deadlineOf(PipelineContext $context): ?string
	{
		if (!DeadlineSentinelGuard::isGivenToTheRun($context))
		{
			return null;
		}

		$deadline = DeadlineSentinelGuard::readFrom($context->getTransit());

		return $deadline === null ? null : (string)$deadline;
	}
}
