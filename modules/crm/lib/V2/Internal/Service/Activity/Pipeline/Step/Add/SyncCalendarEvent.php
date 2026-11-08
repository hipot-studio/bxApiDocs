<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Integration\Bitrix24Manager;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Integration\Calendar\ActivityCalendarSync;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CalendarInputOfTheCaller;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\CalendarCapabilityInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Puts a new activity into the calendar of the person responsible for it: step 28 of the owner table of an add
 * (crm_activity.php:355-385), checklist items G1.1-G1.5, G2.* and G3.
 *
 * THE STEP GATES, THE LAYER WRITES. Every condition the calendar block of legacy runs under is here - the
 * predicates of the provider, the option of the call switching the calendar off, an activity that already has an
 * event, the completion logic and the feature flag of the location - while the whole mapping of an activity onto
 * an event lives in {@see ActivityCalendarSync}. The division is the one item G1 draws: those conditions are
 * conditions of the pipeline and none of them is knowledge about a calendar.
 *
 * AN EMPTY ANSWER OF THE LAYER MEANS "NOTHING TO WRITE BACK" and never an error: no calendar module on the
 * portal, no responsible, a calendar that refused the save. Legacy states the same thing by writing the id of
 * the event only where its own save answered with a positive integer, so a run that got nothing simply leaves
 * the activity without an event.
 *
 * THE ID OF THE EVENT IS WRITTEN TWICE, INTO TWO DIFFERENT PLACES. Once into the row of the activity, past the
 * pipeline and without touching anything else ({@see ActivityCalendarSync::setEventId()}), and once into the
 * column of the transit, because everything below this step - the subscribers of the event of an added activity
 * among them - reads it there. Legacy does exactly this pair (:381-383).
 *
 * THE CALENDAR-ONLY INPUTS ARE CONSUMED HERE, AND THE STEPS BELOW DO NOT SEE THEM (section 13 item 7 of LBS-1,
 * item G3). Legacy deletes five keys out of its field array as the calendar block reads them - the section, the
 * access codes of the attendees, the meeting flag, the user field of the bound objects and the location - so the
 * timeline, the user fields, the hooks of the provider and the subscribers below all see an array without them.
 * Of those five, one is a storage column of V2 and travels in the transit: the location, which this step takes
 * out once the layer has had it. The other four are no columns of an activity at all - they are arguments of the
 * call to the calendar - so they travel with the operation
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::getCalendarInput()}) and no step below
 * reads them from there. What DOES have to be reproduced is the removal itself, because a legacy caller passed
 * those keys in an array of its own and reads that array afterwards: this step therefore states the fact of the
 * consumption ({@see PipelineContext::RUN_VALUE_CALENDAR_INPUTS_CONSUMED}) and the reverse projection of the facade
 * takes the keys out of the array of its caller.
 *
 * AND ONLY WHERE THE CALENDAR BLOCK REALLY RAN. Legacy reads those keys past the guards of its own block, and for an
 * activity without a responsible or a portal without the module it returns before the first of them (:7322-7330) -
 * all five then live on, visible to the steps below and to the array of the caller. The consumption is stated here
 * under that same condition ({@see ActivityCalendarSync::canSyncFor()}). The one exception is the location of a
 * portal whose feature switch forbids one: legacy drops that key before the block, guards or no guards (:383-386).
 *
 * THE ENRICHED DESCRIPTION COMES OFF THE RUN. The check of the fields of a provider derives the text the event
 * is to be described with, and its only carrier is
 * {@see PipelineContext::RUN_VALUE_ENRICHED_DESCRIPTION} - a value of the run, since it is no column either.
 * Without reading it here the event of a to-do would be described with the bare description of the activity.
 *
 * @internal
 */
final class SyncCalendarEvent implements PipelineStepInterface
{
	/**
	 * There is no activity to give an event to before the row exists. Legacy cannot reach this state: it
	 * interrupts an add on a failed insert.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_CALENDAR_EVENT_WITHOUT_ACTIVITY';

	/**
	 * The feature of the portal the location of an activity is allowed by. Switched off, legacy drops the
	 * location before it hands the activity to the calendar (:373-376).
	 */
	private const FEATURE_LOCATION = 'calendar_location';

	private const COLUMN_CALENDAR_EVENT_ID = 'CALENDAR_EVENT_ID';

	private const COLUMN_LOCATION = 'LOCATION';

	private readonly ActivityCalendarSync $calendar;

	/**
	 * @param ActivityCalendarSync|null $calendar the calendar layer of an activity; the one seam of the step.
	 */
	public function __construct(?ActivityCalendarSync $calendar = null)
	{
		$this->calendar = $calendar ?? new ActivityCalendarSync();
	}

	public function getName(): string
	{
		return 'SyncCalendarEvent';
	}

	/**
	 * The whole composite gate of legacy, asked from the cheapest question to the dearest: the provider takes
	 * part in calendars at all, it creates events for activities of this kind, the caller did not switch the
	 * calendar off, the activity has no event yet, a completed activity is allowed to keep one - and last, the
	 * provider does not skip this very operation, which is the only question that costs anything.
	 *
	 * THE COMPLETION HERE IS THE PLAIN FLAG and not the one the statistics refine with the status: legacy reads
	 * `COMPLETED === 'Y'` on its own inside the calendar block (:366) while the steps of the statistics use the
	 * refined answer. Merging the two would put an activity with a status of waiting on the wrong side of the
	 * question about keeping a completed one in the calendar.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		$provider = $context->getProvider();
		if (!$provider instanceof CalendarCapabilityInterface)
		{
			return false;
		}

		$activity = $context->getActivity();
		if (!$provider->canAddCalendarEvents($activity->getProviderTypeId()))
		{
			return false;
		}

		if ($context->getOperationContext()->shouldSkipCalendarEvent())
		{
			return false;
		}

		if (self::eventIdOf($context->getTransit()) > 0)
		{
			return false;
		}

		if (
			$activity->isCompleted() === true
			&& !$provider->canKeepCompletedInCalendar($activity->getProviderTypeId())
		)
		{
			return false;
		}

		return !$provider->skipCalendarSync($activity, $context->getOperationContext());
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		$activity = $context->getActivity();
		$transit = $context->getTransit();
		if (!self::isLocationAllowed())
		{
			$transit->remove(self::COLUMN_LOCATION);
		}

		$areTheInputsRead = $this->calendar->canSyncFor($activity);

		$eventId = $this->calendar->createEvent(
			$activity,
			$context->getPayload(),
			$transit,
			self::calendarInputOf($context),
		);

		if ($areTheInputsRead)
		{
			// The location is an input of the calendar and of nothing else: see the note of the class on item G3.
			$transit->remove(self::COLUMN_LOCATION);
			$context->setRunValue(PipelineContext::RUN_VALUE_CALENDAR_INPUTS_CONSUMED, true);
		}

		if ($eventId !== null && $eventId > 0)
		{
			$this->calendar->setEventId($activityId, $eventId);
			$transit->set(self::COLUMN_CALENDAR_EVENT_ID, $eventId);
		}

		return new Result();
	}

	/**
	 * An activity whose event could not be created is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The inputs of the call that belong to the calendar alone. Four of the six come from the caller and travel with
	 * the operation; the other two have a producer inside the pipeline - the enriched description and the text the
	 * event carried before it, both left behind by the check of the fields - and they are carried only where that
	 * step really produced them, because the layer tells a value that was given from one that was not.
	 *
	 * @return array<string, mixed>
	 */
	private static function calendarInputOf(PipelineContext $context): array
	{
		$input = CalendarInputOfTheCaller::of($context->getOperationContext());

		if ($context->hasRunValue(PipelineContext::RUN_VALUE_ENRICHED_DESCRIPTION))
		{
			$input[ActivityCalendarSync::INPUT_DESCRIPTION] =
				$context->getRunValue(PipelineContext::RUN_VALUE_ENRICHED_DESCRIPTION)
			;
		}

		if ($context->hasRunValue(PipelineContext::RUN_VALUE_PREVIOUS_ENRICHED_DESCRIPTION))
		{
			$input[ActivityCalendarSync::INPUT_PREVIOUS_DESCRIPTION] =
				$context->getRunValue(PipelineContext::RUN_VALUE_PREVIOUS_ENRICHED_DESCRIPTION)
			;
		}

		return $input;
	}

	private static function eventIdOf(StorageFieldTransit $transit): int
	{
		return $transit->has(self::COLUMN_CALENDAR_EVENT_ID) ? (int)$transit->get(self::COLUMN_CALENDAR_EVENT_ID) : 0;
	}

	private static function isLocationAllowed(): bool
	{
		return Bitrix24Manager::isFeatureEnabled(self::FEATURE_LOCATION);
	}
}
