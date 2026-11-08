<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Settings;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Service\Activity\Legacy\LivefeedBridge;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Moves the subscription of the activity stream from the person who was responsible for the activity to the one who is
 * now: row 43 of the owner table STEP-UPDATE (crm_activity.php:1165-1180).
 *
 * THE PART OF THE LEGACY BLOCK THAT STANDS OUTSIDE ITS GATE. Everything else about the activity stream happens only
 * where the caller asked for a record; this happens on any update that changed the responsible, records or no records,
 * legacy facade or not. That is why it is a step of its own and not a branch of the transitional layer - and why its
 * three questions have nothing to do with the entry of the run.
 *
 * THE GATE IS THE CONDITION OF LEGACY, AND ITS FIRST HALF IS A FACT NOBODY OWNS ALONE. Legacy asks "was a record of
 * the stream not registered", keeping the answer in a local boolean it raises in two unrelated places: where the
 * provider of the activity refuses the stream (:1101-1104) and where the fallback registration of an update really
 * wrote a record (:1150). The second half is announced by the layer through
 * {@see PipelineContext::RUN_VALUE_SONET_RECORD_REGISTERED}; the first cannot be, because legacy raises it even where
 * the gate of the layer never opened - a native entry, or an update nobody asked a record of - and there the
 * replacement of the subscription really is performed by legacy as well. So the step asks the predicate of the refusal
 * itself, and it asks the one that already exists
 * ({@see LivefeedBridge::providerRefusesTheActivityStream()}) rather than a copy of its own.
 *
 * THE ACTIVITY CHANGING HANDS IS THE PAIR OF THE SET OF CHANGES. `responsibleId` is answered by the differ of the
 * single-valued fields standing below the exclusive block, and a set without that pair means nobody changed anything:
 * the same reading row 44 makes. The predicate of the block itself is not asked here - it belongs to the block, three
 * dozen rows above, and it is not asked of every run.
 *
 * THE EFFECT IS THE LAYER'S. The subscriptions of the activity stream belong to the transitional layer, which already
 * subscribes the responsible of a record it registered, so the move is asked of it
 * ({@see LivefeedBridge::moveSubscriptionOfTheResponsible()}). The step owns the gate and nothing else.
 *
 * @internal
 */
final class MoveSonetSubscription implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. A
	 * subscription is to an activity, so there is nothing to move.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'MoveSonetSubscription';
	}

	/**
	 * The gate of legacy, in the order that asks the cheap questions first: the activity really changed hands, this
	 * portal generates records of the stream at all, and no record was registered for this activity - the last being
	 * the only question that resolves anything.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if (!$context->getChanges()->isChanged(ActivityChangeSet::responsibleId))
		{
			return false;
		}

		if (!Settings\Crm::isLiveFeedRecordsGenerationEnabled())
		{
			return false;
		}

		return !self::wasARecordRegistered($context);
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		$changes = $context->getChanges();
		LivefeedBridge::moveSubscriptionOfTheResponsible(
			$activityId,
			(int)$changes->getCurrent(ActivityChangeSet::responsibleId),
			(int)$changes->getPrevious(ActivityChangeSet::responsibleId),
			$context->getOperationContext()->shouldRegisterSonetEvent(),
		);

		return $result;
	}

	/**
	 * An activity whose subscription could not be moved is written all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * Whether this run counts as having registered a record of the activity stream. See the note of the class on the
	 * two halves of the answer.
	 */
	private static function wasARecordRegistered(PipelineContext $context): bool
	{
		if ($context->hasRunValue(PipelineContext::RUN_VALUE_SONET_RECORD_REGISTERED))
		{
			return true;
		}

		return LivefeedBridge::providerRefusesTheActivityStream($context);
	}
}
