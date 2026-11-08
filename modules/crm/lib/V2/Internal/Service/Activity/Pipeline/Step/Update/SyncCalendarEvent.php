<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Integration\Bitrix24Manager;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Integration\Calendar\ActivityCalendarSync;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CalendarInputOfTheCaller;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\CalendarCapabilityInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Keeps the event of the calendar of a stored activity in step with it, and takes the event away from an activity
 * that has just been completed and may not keep one: row 39 of the owner table STEP-UPDATE
 * (crm_activity.php:995-1083), checklist items G1.1-G1.2, G2.* and G3, row D10 of the registry.
 *
 * THE ONE ROW OF AN UPDATE THAT IS ASKED ABOUT THE INPUT (item G1.2, row D10). Everything else below the re-read
 * computes its conditions from the state the row now has; the predicate of the skip is defined over the fields the
 * caller handed over ({@see PipelineContext::getInputActivity()}, and the payload and the columns of the same input
 * through the context of the operation), because the one implementation of it decides by the identifier of an
 * already bound event and by the section kept in the settings - values of the input. Asked about the re-read row it
 * would answer about the state before the operation, and it would be handed an array carrying the identifier and
 * the completion the field array of a partial update never has.
 *
 * TWO BRANCHES, AND THE SECOND OF THEM ZEROES THE BINDING. An activity that is not complete, or whose provider
 * keeps completed ones in the calendar, has its event brought in step; an activity that has just been completed
 * without that permission loses the event and the column naming it - legacy performs that zeroing through the
 * integer its own variable still holds (:1007, :1077-1082), and the column is written even where there was no event
 * to remove. The identifier travels back into the column of the transit as well, because everything below reads it
 * there: the timeline of row 41, the subscribers of row 47.
 *
 * THE STEP GATES AND THE LAYER WRITES, exactly as on an add: the predicates of the provider, the option of the
 * call, the completion logic and the feature flag of the location are conditions of the pipeline, while the whole
 * mapping of an activity onto an event lives in {@see ActivityCalendarSync}. The reverse direction is suppressed
 * inside the layer - the re-entrancy guard of the calendar wraps the save and the removal alike, and without it an
 * edit of an activity coming out of the calendar would come round a second time.
 *
 * THE LOCATION IS ARRANGED HERE AND THE ARRANGEMENT IS WRITTEN INTO THE STATE OF THE RUN. Legacy assembles it in
 * four branches over the cur snapshot (:1040-1058) and that snapshot is what the rows below it read, so the column
 * of the transit is the faithful place for the answer: the address of the settings wins, a location the caller named
 * survives only while the feature of the portal allows one and falls back to the stored one otherwise, and an update
 * that named neither CLEARS the location. The last of the four is bug-for-bug: an update saying nothing about the
 * location of an activity takes the location off its event.
 *
 * THE FOUR CALENDAR-ONLY INPUTS OF A CALL COME OFF THE OPERATION - the section, the access codes of the attendees,
 * the meeting flag and the user field of the bound objects. None of them is a column of an activity, so their carrier
 * is the boundary the input arrives through
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::getCalendarInput()}), and without it a
 * meeting written through V2 reached the calendar with no attendees at all. The two that have a producer inside the
 * pipeline are read off the run instead: the text a provider enriched the description with and the text the event
 * carried before it.
 *
 * @internal
 */
final class SyncCalendarEvent implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. An event
	 * is bound to an activity, so there is nothing to bring in step.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The feature of the portal the location of an activity is allowed by. Switched off, legacy keeps the location
	 * the activity already had instead of the one the caller named (:1046-1053).
	 */
	private const FEATURE_LOCATION = 'calendar_location';

	private const COLUMN_CALENDAR_EVENT_ID = 'CALENDAR_EVENT_ID';

	private const COLUMN_LOCATION = 'LOCATION';

	/** The formatted address of the settings of a provider, which beats the location of the activity. */
	private const SETTING_ADDRESS = 'ADDRESS_FORMATTED';

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
	 * The composite gate of legacy, asked from the cheapest question to the dearest: the provider takes part in
	 * calendars at all, it has events for activities of this kind, the caller did not switch the calendar off - and
	 * last the predicate of the skip, which is the only question that costs anything.
	 *
	 * THE COMPLETION IS NOT ASKED HERE. Unlike an add, an update of a completed activity is not a run that does
	 * nothing: it is the run that takes the event away. The branch is therefore part of the execution.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		$provider = $context->getProvider();
		if (!$provider instanceof CalendarCapabilityInterface)
		{
			return false;
		}

		if (!$provider->canAddCalendarEvents($context->getActivity()->getProviderTypeId()))
		{
			return false;
		}

		if ($context->getOperationContext()->shouldSkipCalendarEvent())
		{
			return false;
		}

		return !$provider->skipCalendarSync($context->getInputActivity(), $context->getOperationContext());
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		if (self::isTheEventToBeGivenUp($context))
		{
			$this->calendar->deleteEvent(self::eventIdOf($context->getTransit()));
			$this->nameTheEvent($context, $activityId, 0);

			return $result;
		}

		$this->arrangeTheLocation($context);

		$eventId = $this->calendar->syncEvent(
			$context->getActivity(),
			$context->getPayload(),
			$context->getTransit(),
			self::calendarInputOf($context),
		);

		if ($eventId !== null)
		{
			$this->nameTheEvent($context, $activityId, $eventId);
		}

		return $result;
	}

	/**
	 * An activity whose event could not be brought in step is written all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * Whether this update takes the event away from the activity instead of bringing it in step: the activity has
	 * just been completed and its provider does not keep completed ones in the calendar.
	 *
	 * THE COMPLETION IS THE PLAIN FLAG and not the one the statistics refine with the status: legacy reads
	 * `COMPLETED === 'Y'` off its re-read row here (:1003) while the steps of the statistics use the refined answer.
	 */
	private static function isTheEventToBeGivenUp(PipelineContext $context): bool
	{
		$activity = $context->getActivity();
		if ($activity->isCompleted() !== true)
		{
			return false;
		}

		$provider = $context->getProvider();

		return $provider instanceof CalendarCapabilityInterface
			&& !$provider->canKeepCompletedInCalendar($activity->getProviderTypeId())
		;
	}

	/**
	 * Writes the identifier of the event into the row of the activity past the pipeline and into the column the rows
	 * below read. Zero is how the binding is dropped.
	 */
	private function nameTheEvent(PipelineContext $context, int $activityId, int $calendarEventId): void
	{
		$this->calendar->setEventId($activityId, $calendarEventId);
		$context->getTransit()->set(self::COLUMN_CALENDAR_EVENT_ID, $calendarEventId);
	}

	/**
	 * The location the event is to carry, written into the state of the run. See the note of the class on the four
	 * branches and on the one that clears it.
	 */
	private function arrangeTheLocation(PipelineContext $context): void
	{
		$transit = $context->getTransit();

		$address = self::addressOf($context->getPayload());
		if ($address !== null)
		{
			$transit->set(self::COLUMN_LOCATION, $address);

			return;
		}

		if (!$context->getOperationContext()->getInputTransit()->has(self::COLUMN_LOCATION))
		{
			$transit->set(self::COLUMN_LOCATION, '');

			return;
		}

		if (self::isLocationAllowed())
		{
			return;
		}

		if ($context->hasPreviousState() && $context->getPreviousTransit()->has(self::COLUMN_LOCATION))
		{
			$transit->set(self::COLUMN_LOCATION, $context->getPreviousTransit()->get(self::COLUMN_LOCATION));
		}
	}

	/**
	 * The inputs of the call that belong to the calendar alone, and only the two the pipeline really produces: the
	 * text the check of the fields enriched the description with and the text the event carried before it. The layer
	 * tells a value that was given from one that was not, so a key nobody produced is not passed at all.
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

	/**
	 * The formatted address of the settings the operation carries, or null where it carries none. Legacy asks it of
	 * the settings the caller gave, which is what the payload of the run holds on an update.
	 */
	private static function addressOf(ProviderPayload $payload): ?string
	{
		if (!$payload->has(ProviderPayload::settings))
		{
			return null;
		}

		$settings = $payload->get(ProviderPayload::settings);

		return is_array($settings) && isset($settings[self::SETTING_ADDRESS])
			? (string)$settings[self::SETTING_ADDRESS]
			: null
		;
	}

	private static function eventIdOf(StorageFieldTransit $transit): int
	{
		return $transit->has(self::COLUMN_CALENDAR_EVENT_ID)
			? (int)$transit->get(self::COLUMN_CALENDAR_EVENT_ID)
			: 0
		;
	}

	private static function isLocationAllowed(): bool
	{
		return Bitrix24Manager::isFeatureEnabled(self::FEATURE_LOCATION);
	}
}
